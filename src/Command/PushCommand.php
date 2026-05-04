<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use KnLab\PbMigrate\Sync\BotSync;
use KnLab\PbMigrate\Sync\CacheStore;
use KnLab\PbMigrate\Sync\DiffEngine;
use KnLab\PbMigrate\Sync\FileScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'push', description: 'Push local AIML files to a bot (add/update/delete) and compile')]
final class PushCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without uploading');
        $this->addOption('skip-compile', null, InputOption::VALUE_NONE, 'Do not run compile after upload');
        $this->addOption('prune', null, InputOption::VALUE_NONE, 'Delete remote files that are missing locally (off by default)');
        $this->addOption('full-check', null, InputOption::VALUE_NONE, 'Bypass the local cache and verify every file against a fresh remote download');
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of names (or kind/name) to push; everything else is skipped');
        $this->addOption('override', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Temporarily swap a file body for this push only. Form: name=path/to/replacement (repeatable)');
        $this->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Confirm each change individually before applying');
        $this->addOption('properties-upload', null, InputOption::VALUE_REQUIRED, 'Override bot.propertiesUpload for this push: "additive" (default) or "full" (delete remote properties first)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bots = $this->resolveBots($config, $input);

        $overrides = $this->parseOverrides((array) $input->getOption('override'));
        $only = $this->parseOnly((string) ($input->getOption('only') ?? ''));
        $cache = CacheStore::forProjectRoot($config->projectRoot);
        $sync = new BotSync($client, new FileScanner(), new DiffEngine(), $cache);

        $propsOverride = $input->getOption('properties-upload');
        if (is_string($propsOverride) && $propsOverride !== '') {
            if (!in_array($propsOverride, [BotConfig::PROPERTIES_UPLOAD_ADDITIVE, BotConfig::PROPERTIES_UPLOAD_FULL], true)) {
                throw new ConfigException(sprintf('--properties-upload must be "additive" or "full", got "%s"', $propsOverride));
            }
            $bots = array_map(
                static fn (BotConfig $b) => new BotConfig($b->name, $b->directory, $b->filesPattern, $propsOverride),
                $bots,
            );
        }

        $totalChanges = 0;
        foreach ($bots as $bot) {
            $totalChanges += $this->runForBot($input, $io, $sync, $bot, $overrides, $only);
        }

        if ($totalChanges === 0) {
            $io->success(sprintf('No changes across %d bot(s)', count($bots)));
        } else {
            $io->success(sprintf('Pushed %d change(s) across %d bot(s)', $totalChanges, count($bots)));
        }
        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $overrides
     * @param list<string> $only
     */
    private function runForBot(
        InputInterface $input,
        \Symfony\Component\Console\Style\SymfonyStyle $io,
        BotSync $sync,
        BotConfig $bot,
        array $overrides,
        array $only,
    ): int {
        // Merge persistent alters from pb-migrate.json with the CLI --override list.
        // CLI wins on conflict so users can layer one-shot tests on top of a
        // persistent debug-session alter set.
        $effectiveOverrides = array_merge($bot->alters, $overrides);

        if ($bot->alters !== []) {
            $io->writeln(sprintf(
                '<comment>%s: applying %d persistent alter(s) from config</comment>',
                $bot->name,
                count($bot->alters),
            ));
        }

        [$changes, $localFiles] = $sync->plan(
            bot: $bot,
            fullCheck: (bool) $input->getOption('full-check'),
            overrides: $effectiveOverrides,
        );

        if ($only !== []) {
            $changes = $changes->filter($only);
        }

        if ($changes->isEmpty()) {
            $io->writeln(sprintf('<info>%s</info>: no changes', $bot->name));
            return 0;
        }

        $io->writeln(sprintf('Push plan for bot <info>%s</info>:', $bot->name));
        foreach ($changes->all() as $change) {
            $io->writeln(sprintf('  [%s] %s/%s', $change->action, $change->kind->value, $change->name));
        }

        if ($input->getOption('dry-run')) {
            $io->writeln('<comment>(dry run — no API calls made)</comment>');
            return $changes->count();
        }

        if ($input->getOption('interactive')) {
            $kept = [];
            foreach ($changes->all() as $change) {
                $label = sprintf('%s %s/%s', $change->action, $change->kind->value, $change->name);
                if ($io->confirm(sprintf('Apply %s?', $label), true)) {
                    $kept[] = $change;
                }
            }
            $changes = $changes->withChanges($kept);
            if ($changes->isEmpty()) {
                $io->writeln('  <comment>(nothing selected)</comment>');
                return 0;
            }
        }

        $sync->applyPush($bot, $changes, $localFiles, $io, prune: (bool) $input->getOption('prune'));

        if ($input->getOption('skip-compile')) {
            $io->writeln(sprintf('  <comment>%s: skipped compile</comment>', $bot->name));
        } else {
            $sync->compile($bot, $io);
        }

        return $changes->count();
    }

    /**
     * @param list<string> $rawOverrides
     * @return array<string, string>
     */
    private function parseOverrides(array $rawOverrides): array
    {
        $out = [];
        foreach ($rawOverrides as $raw) {
            if (!str_contains($raw, '=')) {
                throw new \InvalidArgumentException(sprintf('--override expects name=path, got: %s', $raw));
            }
            [$name, $path] = explode('=', $raw, 2);
            $name = trim($name);
            $path = trim($path);
            if ($name === '' || $path === '') {
                throw new \InvalidArgumentException(sprintf('--override has empty name or path: %s', $raw));
            }
            $out[$name] = $path;
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private function parseOnly(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
    }
}
