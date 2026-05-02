<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bot:list', description: 'List bots on Pandorabots')]
final class BotListCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        // Override AbstractBotCommand: --bot is not relevant here.
        $this->addOption('config', 'c', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Path to pb-migrate.json', \KnLab\PbMigrate\Config\ProjectConfig::DEFAULT_FILENAME);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);

        $bots = $client->getBotsList();
        if ($bots === []) {
            $io->writeln('<comment>(no bots)</comment>');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($bots as $bot) {
            $rows[] = [
                $bot->botname ?? '',
                $bot->language ?? '',
                ($bot->compiled ?? false) ? 'yes' : 'no',
            ];
        }

        $io->table(['botname', 'language', 'compiled'], $rows);
        return Command::SUCCESS;
    }
}
