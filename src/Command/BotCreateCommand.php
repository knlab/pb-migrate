<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bot:create', description: 'Create a new bot on Pandorabots')]
final class BotCreateCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('botname', InputArgument::REQUIRED, 'Name of the bot to create');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);

        $botname = (string) $input->getArgument('botname');
        $client->create($botname);

        $io->success(sprintf('Created bot: %s', $botname));
        return Command::SUCCESS;
    }
}
