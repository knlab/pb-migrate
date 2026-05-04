<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Exception\ConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bot:delete', description: 'Delete a registered bot on Pandorabots (local registration is preserved; use `pb-migrate remove` to unregister)')]
final class BotDeleteCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('botname', InputArgument::REQUIRED, 'Name of the bot to delete on Pandorabots');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);

        $botname = (string) $input->getArgument('botname');
        if ($botname === '') {
            throw new ConfigException('botname is required');
        }
        if (!$config->hasBot($botname)) {
            throw new ConfigException(sprintf(
                'bot "%s" is not registered locally. (Hint: `pb-migrate bot:remote` lists what exists on the account.)',
                $botname,
            ));
        }

        if (!$input->getOption('yes')) {
            $confirmed = $io->confirm(sprintf(
                'Really delete bot "%s" on Pandorabots? This is irreversible. (Local registration will be preserved; run `pb-migrate remove` to unregister.)',
                $botname,
            ), false);
            if (!$confirmed) {
                $io->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $client = $this->client($config);
        $client->delete($botname);
        $io->success(sprintf('Deleted bot on Pandorabots: %s', $botname));
        return Command::SUCCESS;
    }
}
