<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Sync\BotSync;
use KnLab\PbMigrate\Sync\DiffEngine;
use KnLab\PbMigrate\Sync\FileScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pull', description: 'Pull remote bot files to the local directory')]
final class PullCommand extends AbstractBotCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bot = $this->resolveBot($config, $input);

        $sync = new BotSync($client, new FileScanner(), new DiffEngine());
        $count = $sync->pull($bot, $io);

        $io->success(sprintf('Pulled %d file(s) for bot "%s" into %s', $count, $bot->name, $bot->directory));
        return Command::SUCCESS;
    }
}
