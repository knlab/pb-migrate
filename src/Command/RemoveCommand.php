<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\EnvFile;
use KnLab\PbMigrate\Config\ProjectConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'remove', description: 'Unregister a bot from pb-migrate.json (does not touch the remote bot or its files)')]
final class RemoveCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('botname', InputArgument::REQUIRED, 'Bot name to unregister');
        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to pb-migrate.json', ProjectConfig::DEFAULT_FILENAME);
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $botname = (string) $input->getArgument('botname');
        if ($botname === '') {
            throw new ConfigException('botname is required');
        }

        $configPath = (string) $input->getOption('config');
        $config = ProjectConfig::load($configPath);

        if (!$config->hasBot($botname)) {
            $io->error(sprintf('bot "%s" is not registered.', $botname));
            return Command::FAILURE;
        }

        if (!$input->getOption('yes')) {
            $confirmed = $io->confirm(sprintf(
                'Unregister bot "%s"? (the remote bot on Pandorabots is NOT touched)',
                $botname,
            ), false);
            if (!$confirmed) {
                $io->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        ProjectConfig::removeBot($config->configPath, $botname);

        // Also remove the bot's bot_key block from .env, if any.
        $envPath = $config->projectRoot . DIRECTORY_SEPARATOR . '.env';
        $envFile = new EnvFile($envPath);
        $envFile->removeBlock(EnvFile::blockIdForBot($botname));

        $io->success(sprintf('Unregistered bot "%s"', $botname));
        return Command::SUCCESS;
    }
}
