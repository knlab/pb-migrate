<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'debug', description: 'Send input to a bot with trace and print the response + trace JSON')]
final class DebugCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('input', InputArgument::REQUIRED, 'User input to send to the bot');
        $this->addOption('client-name', null, InputOption::VALUE_REQUIRED, 'client_name', '');
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'sessionid', '');
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Reset conversation state');
        $this->addOption('extra', null, InputOption::VALUE_NONE, 'Include extra information in trace');
        $this->addOption('reload', null, InputOption::VALUE_NONE, 'Reload bot before processing');
        $this->addOption('no-trace', null, InputOption::VALUE_NONE, 'Disable trace (default: enabled)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bot = $this->resolveBot($config, $input);

        $reply = $client->debug(
            input: (string) $input->getArgument('input'),
            botname: $bot->name,
            clientName: (string) $input->getOption('client-name'),
            sessionId: (string) $input->getOption('session'),
            reset: (bool) $input->getOption('reset'),
            extra: (bool) $input->getOption('extra'),
            trace: !$input->getOption('no-trace'),
            reload: (bool) $input->getOption('reload'),
        );

        $io->writeln(json_encode($reply, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        return Command::SUCCESS;
    }
}
