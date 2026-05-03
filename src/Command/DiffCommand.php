<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Sync\BotSync;
use KnLab\PbMigrate\Sync\CacheStore;
use KnLab\PbMigrate\Sync\DiffEngine;
use KnLab\PbMigrate\Sync\FileChange;
use KnLab\PbMigrate\Sync\FileScanner;
use Spontena\PbPhp\PBClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'diff', description: 'Show unified diff between local and remote bot files')]
final class DiffCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('full-check', null, InputOption::VALUE_NONE, 'Bypass the local cache and verify every file against a fresh remote download');
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
        $diff = new DiffEngine();
        $sync = new BotSync($client, new FileScanner(), $diff, $cache);

        $hasAny = false;
        foreach ($bots as $bot) {
            [$changes] = $sync->plan($bot, fullCheck: (bool) $input->getOption('full-check'));
            if ($only !== []) {
                $changes = $changes->filter($only);
            }
            if ($changes->isEmpty()) {
                $io->writeln(sprintf('<info>%s</info>: no differences', $bot->name));
                continue;
            }
            $hasAny = true;
            $io->writeln(sprintf('<info>%s</info>:', $bot->name));
            $this->renderChanges($io, $diff, $client, $bot, $changes->all());
        }

        if (!$hasAny) {
            $io->writeln('<info>(no differences)</info>');
        }
        return Command::SUCCESS;
    }

    /**
     * @param list<FileChange> $changes
     */
    private function renderChanges(SymfonyStyle $io, DiffEngine $diff, PBClient $client, BotConfig $bot, array $changes): void
    {
        foreach ($changes as $change) {
            $label = $change->kind->value . '/' . $change->name;
            switch ($change->action) {
                case FileChange::ADD:
                    $io->writeln(sprintf('  <fg=green># local-only: %s</>', $label));
                    break;
                case FileChange::DELETE:
                    $io->writeln(sprintf('  <fg=red># remote-only: %s</>', $label));
                    break;
                case FileChange::UPDATE:
                    if ($change->localPath === null) {
                        continue 2;
                    }
                    $localContent = (string) file_get_contents($change->localPath);
                    $remoteContent = $client->getBotFile(
                        kind: $change->kind,
                        botname: $bot->name,
                        name: $change->kind->hasFilenameInPath() ? $change->name : null,
                    );
                    $io->writeln($diff->unified($localContent, $remoteContent, $label));
                    break;
            }
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
