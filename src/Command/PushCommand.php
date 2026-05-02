<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bot = $this->resolveBot($config, $input);

        $overrides = $this->parseOverrides((array) $input->getOption('override'));
        $only = $this->parseOnly((string) ($input->getOption('only') ?? ''));

        $cache = CacheStore::forProjectRoot($config->projectRoot);
        $sync = new BotSync($client, new FileScanner(), new DiffEngine(), $cache);
        [$changes, $localFiles] = $sync->plan(
            bot: $bot,
            fullCheck: (bool) $input->getOption('full-check'),
            overrides: $overrides,
        );

        if ($only !== []) {
            $changes = $changes->filter($only);
        }

        if ($changes->isEmpty()) {
            $io->success(sprintf('No changes for bot "%s"', $bot->name));
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Push plan for bot <info>%s</info>:', $bot->name));
        foreach ($changes->all() as $change) {
            $io->writeln(sprintf('  [%s] %s/%s', $change->action, $change->kind->value, $change->name));
        }

        if ($input->getOption('dry-run')) {
            $io->writeln('<comment>(dry run — no API calls made)</comment>');
            return Command::SUCCESS;
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
                $io->writeln('<comment>(nothing selected)</comment>');
                return Command::SUCCESS;
            }
        }

        $sync->applyPush($bot, $changes, $localFiles, $io, prune: (bool) $input->getOption('prune'));

        if ($input->getOption('skip-compile')) {
            $io->note('Skipped compile (--skip-compile)');
        } else {
            $sync->compile($bot, $io);
        }

        $io->success(sprintf('Pushed %d change(s) to bot "%s"', $changes->count(), $bot->name));
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $rawOverrides each in the form "name=path"
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
