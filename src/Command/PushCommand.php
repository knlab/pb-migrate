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
        $this->addOption('keep-remote-only', null, InputOption::VALUE_NONE, 'Preserve remote-only files (default behaviour rewrites remote to match local, deleting extras)');
        $this->addOption('verify-remote', null, InputOption::VALUE_NONE, 'Bypass the local cache and verify every file against a fresh remote download');
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of names (or kind/name) to push; everything else is skipped');
        $this->addOption('override', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Temporarily swap a file body for this push only. Form: name=path/to/replacement (repeatable)');
        $this->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Confirm each change individually before applying');
        $this->addOption('properties-upload', null, InputOption::VALUE_REQUIRED, 'Override bot.propertiesUpload for this push: "additive" or "full" (delete remote properties first)');
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
                static fn (BotConfig $b) => new BotConfig($b->name, $b->directory, $propsOverride, $b->alters),
                $bots,
            );
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $totalChanges = 0;
        foreach ($bots as $bot) {
            $totalChanges += $this->runForBot($input, $io, $sync, $bot, $overrides, $only);
        }

        if ($totalChanges === 0) {
            $io->success(sprintf('No changes across %d bot(s)', count($bots)));
        } else {
            $verb = $dryRun ? 'Would push' : 'Pushed';
            $io->success(sprintf('%s %d change(s) across %d bot(s)', $verb, $totalChanges, count($bots)));
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
                '<fg=yellow>%s: %d active alter(s) detected:</>',
                $bot->name,
                count($bot->alters),
            ));
            foreach ($bot->alters as $alterName => $alterPath) {
                $io->writeln(sprintf('  - %s → %s', $alterName, $alterPath));
            }
            $io->writeln(sprintf(
                '<comment>(use `pb-migrate alter:reset --bot %s` to clear before production push)</comment>',
                $bot->name,
            ));
        }

        [$changes, $localFiles] = $sync->plan(
            bot: $bot,
            fullCheck: (bool) $input->getOption('verify-remote'),
            overrides: $effectiveOverrides,
        );

        if ($only !== []) {
            $changes = $changes->filter($only);
        }

        // Default behaviour is destructive: remote-only files are deleted to
        // match local. --keep-remote-only flips it to additive (legacy "no prune").
        $prune = !$input->getOption('keep-remote-only');

        // Drop DELETEs from the plan when --keep-remote-only — they won't run,
        // and listing them as `[delete]` in the output misleads the user.
        $remoteOnlyKept = 0;
        if (!$prune) {
            $deletes = $changes->byAction(\KnLab\PbMigrate\Sync\FileChange::DELETE);
            $remoteOnlyKept = count($deletes);
            $nonDeletes = array_filter(
                $changes->all(),
                static fn ($c) => $c->action !== \KnLab\PbMigrate\Sync\FileChange::DELETE,
            );
            $changes = $changes->withChanges(array_values($nonDeletes));
        }

        if ($changes->isEmpty() && $remoteOnlyKept === 0) {
            $io->writeln(sprintf('<info>%s</info>: no changes', $bot->name));
            return 0;
        }

        $io->writeln(sprintf('Push plan for bot <info>%s</info>:', $bot->name));
        foreach ($changes->all() as $change) {
            $io->writeln(sprintf('  [%s] %s/%s', $change->action, $change->kind->value, $change->name));
        }
        if ($remoteOnlyKept > 0) {
            $io->writeln(sprintf(
                '  <comment>(%d remote-only file(s) preserved — --keep-remote-only mode)</comment>',
                $remoteOnlyKept,
            ));
        }

        if ($input->getOption('dry-run')) {
            $io->writeln('<comment>(dry run — no API calls made)</comment>');
            return $changes->count();
        }

        if ($changes->isEmpty()) {
            return 0;
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

        $applied = $sync->applyPush($bot, $changes, $localFiles, $io, prune: $prune);

        if ($input->getOption('skip-compile')) {
            $io->writeln(sprintf('  <comment>%s: skipped compile</comment>', $bot->name));
        } else {
            $sync->compile($bot, $io);
        }

        return $applied;
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
