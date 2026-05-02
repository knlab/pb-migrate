<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bot:delete', description: 'Delete a bot on Pandorabots')]
final class BotDeleteCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('botname', InputArgument::REQUIRED, 'Name of the bot to delete');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);

        $botname = (string) $input->getArgument('botname');

        if (!$input->getOption('yes')) {
            $confirmed = $io->confirm(sprintf('Really delete bot "%s"? This cannot be undone.', $botname), false);
            if (!$confirmed) {
                $io->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $client->delete($botname);
        $io->success(sprintf('Deleted bot: %s', $botname));
        return Command::SUCCESS;
    }
}
