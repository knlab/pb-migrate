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

#[AsCommand(name: 'report', description: 'Generate an inspection report of pending changes (e.g. for a release handoff document)')]
final class ReportCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('next-push', null, InputOption::VALUE_NONE, 'Report what would be sent on the next push (default if no other mode is specified)');
        $this->addOption('full-check', null, InputOption::VALUE_NONE, 'Bypass the local cache and verify every file against a fresh remote download');
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of names (or kind/name) to include');
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

        $totalAdd = $totalUpdate = $totalDelete = 0;
        foreach ($bots as $bot) {
            [$changes] = $sync->plan($bot, fullCheck: (bool) $input->getOption('full-check'));
            if ($only !== []) {
                $changes = $changes->filter($only);
            }

            $this->renderBotSection($io, $bot, $changes);

            $totalAdd += count($changes->byAction(FileChange::ADD));
            $totalUpdate += count($changes->byAction(FileChange::UPDATE));
            $totalDelete += count($changes->byAction(FileChange::DELETE));
        }

        $io->writeln('');
        $io->writeln(sprintf(
            '<info>Total:</info> (+) %d add  (*) %d update  (-) %d remote-only',
            $totalAdd,
            $totalUpdate,
            $totalDelete,
        ));

        return Command::SUCCESS;
    }

    private function renderBotSection(\Symfony\Component\Console\Style\SymfonyStyle $io, BotConfig $bot, FileChangeSet $changes): void
    {
        $io->writeln('');
        $io->writeln(sprintf('Pending changes for bot <info>%s</info>', $bot->name));
        $io->writeln('─────────────────────────────────────────────');

        if ($changes->isEmpty()) {
            $io->writeln('  <comment>(no pending changes)</comment>');
            return;
        }

        foreach ($changes->all() as $change) {
            [$badge, $color] = $this->badge($change->action);
            $size = $this->describeSize($change);
            $io->writeln(sprintf(
                '  <fg=%s>%s</> %s/%s%s',
                $color,
                $badge,
                $change->kind->value,
                $change->name,
                $size === '' ? '' : '  ' . $size,
            ));
        }

        $a = count($changes->byAction(FileChange::ADD));
        $u = count($changes->byAction(FileChange::UPDATE));
        $d = count($changes->byAction(FileChange::DELETE));
        $io->writeln('');
        $io->writeln(sprintf('  <comment>Summary: %d add, %d update, %d remote-only</comment>', $a, $u, $d));
    }

    /**
     * @return array{0: string, 1: string} badge marker, color name
     */
    private function badge(string $action): array
    {
        return match ($action) {
            FileChange::ADD => ['(+)', 'green'],
            FileChange::UPDATE => ['(*)', 'yellow'],
            FileChange::DELETE => ['(-)', 'red'],
            default => ['(?)', 'default'],
        };
    }

    private function describeSize(FileChange $change): string
    {
        if ($change->localPath === null || !is_file($change->localPath)) {
            return $change->action === FileChange::DELETE ? '<comment>(remote-only)</comment>' : '';
        }
        $bytes = filesize($change->localPath);
        if ($bytes === false) {
            return '';
        }
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
