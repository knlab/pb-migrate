<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Sync\BotSync;
use KnLab\PbMigrate\Sync\CacheStore;
use KnLab\PbMigrate\Sync\DiffEngine;
use KnLab\PbMigrate\Sync\FileChange;
use KnLab\PbMigrate\Sync\FileChangeSet;
use KnLab\PbMigrate\Sync\FileScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'diff', description: 'Show file-level differences between local and remote bots (UPD/ADD/DEL grouped, color-coded)')]
final class DiffCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('verify-remote', null, InputOption::VALUE_NONE, 'Bypass the local cache and verify every file against a fresh remote download');
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of names (or kind/name) to diff; everything else is omitted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bots = $this->resolveBots($config, $input);

        $only = $this->parseOnly((string) ($input->getOption('only') ?? ''));
        $cache = CacheStore::forProjectRoot($config->projectRoot);
        $sync = new BotSync($client, new FileScanner(), new DiffEngine(), $cache);

        $hasAny = false;
        foreach ($bots as $bot) {
            [$changes] = $sync->plan($bot, fullCheck: (bool) $input->getOption('verify-remote'));
            if ($only !== []) {
                $changes = $changes->filter($only);
            }
            $this->renderBot($io, $config->host(), $config->appId(), $bot, $changes);
            if (!$changes->isEmpty()) {
                $hasAny = true;
            }
        }

        if (!$hasAny) {
            $io->writeln('');
            $io->writeln('(no differences)');
        }
        return Command::SUCCESS;
    }

    private function renderBot(SymfonyStyle $io, string $host, string $appId, BotConfig $bot, FileChangeSet $changes): void
    {
        $io->writeln('');
        $io->writeln(sprintf('%s:', $bot->name));
        $io->writeln(sprintf('URL: %s', $host));
        $io->writeln(sprintf('BOT: %s/%s', $appId, $bot->name));

        if ($changes->isEmpty()) {
            $io->writeln('  (no differences)');
            return;
        }

        $updates = $changes->byAction(FileChange::UPDATE);
        $adds = $changes->byAction(FileChange::ADD);
        $deletes = $changes->byAction(FileChange::DELETE);

        $this->renderGroup($io, 'UPD', $updates, 'yellow');
        $this->renderGroup($io, 'ADD', $adds, 'green');
        $this->renderGroup($io, 'DEL', $deletes, 'red');
    }

    /**
     * @param list<FileChange> $changes
     */
    private function renderGroup(SymfonyStyle $io, string $label, array $changes, string $color): void
    {
        if ($changes === []) {
            return;
        }
        $io->writeln('');
        $io->writeln(sprintf('<fg=%s>%s(%d):</>', $color, $label, count($changes)));
        foreach ($changes as $change) {
            $io->writeln(sprintf('    %s/%s', $change->kind->value, $change->name));
        }
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
