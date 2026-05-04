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

#[AsCommand(name: 'pull', description: 'Pull remote bot files to the local directory')]
final class PullCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of names (or kind/name) to pull; everything else is skipped');
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

        $total = 0;
        foreach ($bots as $bot) {
            $io->writeln(sprintf('%s:', $bot->name));
            $count = $sync->pull($bot, $io, $only);
            $total += $count;
        }

        $io->success(sprintf('Pulled %d file(s) across %d bot(s)', $total, count($bots)));
        return Command::SUCCESS;
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
