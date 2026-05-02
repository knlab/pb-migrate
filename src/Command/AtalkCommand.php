<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'atalk', description: 'Anonymous talk via botkey (POST /talk?botkey=...)')]
final class AtalkCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('input', InputArgument::REQUIRED, 'User input to send to the bot');
        $this->addOption('client-name', null, InputOption::VALUE_REQUIRED, 'client_name', '');
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'sessionid', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);

        if ($config->botKey === null) {
            $io->error('atalk requires PB_BOT_KEY (botKey in pb-migrate.json) to be set.');
            return Command::FAILURE;
        }

        $client = $this->client($config);
        $reply = $client->atalk(
            input: (string) $input->getArgument('input'),
            clientName: (string) $input->getOption('client-name'),
            sessionId: (string) $input->getOption('session'),
        );

        foreach (($reply->responses ?? []) as $line) {
            $io->writeln((string) $line);
        }
        return Command::SUCCESS;
    }
}
