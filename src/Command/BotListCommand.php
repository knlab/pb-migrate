<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\ProjectConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bot:list', description: 'List bots registered locally in pb-migrate.json (no API call). For account-wide listing, use `bot:remote`.')]
final class BotListCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        // Local-only, no --bot/--all needed.
        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to pb-migrate.json', ProjectConfig::DEFAULT_FILENAME);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);

        $bots = $config->bots();
        if ($bots === []) {
            $io->writeln('(no bots registered. Run `pb-migrate add <directory>` to register one.)');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($bots as $name => $bot) {
            $rows[] = [
                $name,
                $bot->directory,
                $bot->propertiesUpload,
                $bot->alters !== [] ? sprintf('%d alter(s)', count($bot->alters)) : '',
            ];
        }

        $this->plainTable($io, ['name', 'directory', 'propertiesUpload', 'alters'], $rows);
        return Command::SUCCESS;
    }
}
