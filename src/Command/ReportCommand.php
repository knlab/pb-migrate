<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use KnLab\PbMigrate\Sync\BotSync;
use KnLab\PbMigrate\Sync\CachePlanner;
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

#[AsCommand(name: 'report', description: 'Generate a richly-formatted handoff report of pending changes (suitable for PR descriptions and release docs)')]
final class ReportCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('verify-remote', null, InputOption::VALUE_NONE, 'Bypass the local cache and verify every file against a fresh remote download');
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of names (or kind/name) to include');
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Diff source: "remote" (default, hits the API) or "cache" (no API; reports local changes since last successful push/pull)');
        $this->addOption('utf8-borders', null, InputOption::VALUE_NONE, 'Use Unicode box-drawing characters for the section borders (default: ASCII === / ---)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);

        $since = (string) ($input->getOption('since') ?? 'remote');
        if (!in_array($since, ['remote', 'cache'], true)) {
            throw new ConfigException(sprintf('--since must be "remote" or "cache", got "%s"', $since));
        }
        if ($since === 'cache' && $input->getOption('verify-remote')) {
            throw new ConfigException('--verify-remote cannot be combined with --since=cache (verify-remote requires API access)');
        }

        $config = $this->loadConfig($input);
        $bots = $this->resolveBots($config, $input);

        $only = $this->parseOnly((string) ($input->getOption('only') ?? ''));
        $cache = CacheStore::forProjectRoot($config->projectRoot);

        $planner = $since === 'cache'
            ? new CachePlanner(new FileScanner(), $cache)
            : new BotSync($this->client($config), new FileScanner(), new DiffEngine(), $cache);

        $useUtf8 = (bool) $input->getOption('utf8-borders');
        $generatedAt = date('Y-m-d H:i');

        $totalAdd = $totalUpdate = $totalDelete = 0;
        foreach ($bots as $bot) {
            $cacheEmpty = $since === 'cache' && $cache->entriesFor($bot->name) === [];

            $changes = $since === 'cache'
                ? $planner->plan($bot)[0]
                : $planner->plan($bot, fullCheck: (bool) $input->getOption('verify-remote'))[0];

            if ($only !== []) {
                $changes = $changes->filter($only);
            }

            $this->renderBotReport($io, $bot, $changes, $since, $generatedAt, $useUtf8, $cacheEmpty);

            $totalAdd += count($changes->byAction(FileChange::ADD));
            $totalUpdate += count($changes->byAction(FileChange::UPDATE));
            $totalDelete += count($changes->byAction(FileChange::DELETE));
        }

        $io->writeln('');
        $io->writeln(sprintf(
            '<info>Total:</info> %d added, %d updated, %d remote-only',
            $totalAdd,
            $totalUpdate,
            $totalDelete,
        ));

        return Command::SUCCESS;
    }

    private function renderBotReport(
        SymfonyStyle $io,
        BotConfig $bot,
        FileChangeSet $changes,
        string $since,
        string $generatedAt,
        bool $useUtf8,
        bool $cacheEmpty,
    ): void {
        $heavy = $useUtf8 ? str_repeat('═', 60) : str_repeat('=', 60);
        $light = $useUtf8 ? '─── %s ' . str_repeat('─', 40) : '--- %s ' . str_repeat('-', 40);

        $heading = $since === 'cache'
            ? sprintf('Local changes for bot %s since last push/pull', $bot->name)
            : sprintf('Pending changes for bot %s', $bot->name);

        $io->writeln('');
        $io->writeln($heavy);
        $io->writeln($heading);
        $io->writeln($heavy);
        $io->writeln(sprintf('Generated: %s (--since=%s)', $generatedAt, $since));

        if ($cacheEmpty) {
            $io->writeln('');
            $io->writeln(sprintf(
                '<comment>No cache reference yet for "%s" — every local file is reported as new. Run `pull` or `push` once to establish a baseline.</comment>',
                $bot->name,
            ));
        }

        if ($changes->isEmpty()) {
            $io->writeln('');
            $io->writeln('  <comment>(no pending changes)</comment>');
            return;
        }

        $updates = $changes->byAction(FileChange::UPDATE);
        $adds = $changes->byAction(FileChange::ADD);
        $deletes = $changes->byAction(FileChange::DELETE);

        $totalLocalBytes = 0;
        $this->renderSection($io, sprintf($light, sprintf('Updates (%d)', count($updates))), $updates, 'yellow', $totalLocalBytes);
        $this->renderSection($io, sprintf($light, sprintf('Additions (%d)', count($adds))), $adds, 'green', $totalLocalBytes);
        $this->renderSection($io, sprintf($light, sprintf('Removals (%d)', count($deletes))), $deletes, 'red', $totalLocalBytes);

        $io->writeln('');
        $io->writeln(sprintf($light, 'Summary'));
        $io->writeln(sprintf(
            '  %d added, %d updated, %d remote-only',
            count($adds),
            count($updates),
            count($deletes),
        ));
        if ($totalLocalBytes > 0) {
            $io->writeln(sprintf('  Total local size: %s', $this->formatBytes($totalLocalBytes)));
        }
    }

    /**
     * @param list<FileChange> $changes
     */
    private function renderSection(SymfonyStyle $io, string $heading, array $changes, string $color, int &$totalBytes): void
    {
        if ($changes === []) {
            return;
        }
        $io->writeln('');
        $io->writeln(sprintf('<fg=%s>%s</>', $color, $heading));
        foreach ($changes as $change) {
            $size = $this->describeSize($change, $totalBytes);
            $io->writeln(sprintf(
                '  %s/%s%s',
                $change->kind->value,
                $change->name,
                $size === '' ? '' : '  ' . $size,
            ));
        }
    }

    private function describeSize(FileChange $change, int &$totalBytes): string
    {
        if ($change->action === FileChange::DELETE || $change->localPath === null) {
            return $change->action === FileChange::DELETE ? '<comment>(remote-only)</comment>' : '';
        }
        if (!is_file($change->localPath)) {
            return '';
        }
        $bytes = filesize($change->localPath);
        if ($bytes === false) {
            return '';
        }
        $totalBytes += $bytes;
        return sprintf('<comment>(%s)</comment>', $this->formatBytes($bytes));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        return number_format($bytes / 1024, 1) . ' KB';
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
