<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\ProjectConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'alter:reset', description: 'Remove all persistent alters from a bot (clears the entire debug-session probe set)')]
final class AlterResetCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $bot = $this->resolveBot($config, $input);

        if ($bot->alters === []) {
            $io->writeln(sprintf('<comment>%s has no alters; nothing to do</comment>', $bot->name));
            return Command::SUCCESS;
        }

        $count = count($bot->alters);
        if (!$input->getOption('yes')) {
            if (!$io->confirm(sprintf('Remove all %d alter(s) from %s?', $count, $bot->name), false)) {
                $io->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $configPath = (string) $input->getOption('config');
        ProjectConfig::saveAlters($configPath, $bot->name, []);

        $io->success(sprintf('Removed %d alter(s) from %s', $count, $bot->name));
        return Command::SUCCESS;
    }
}
