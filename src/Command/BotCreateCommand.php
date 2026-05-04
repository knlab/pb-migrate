<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Exception\ConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bot:create', description: 'Create a registered bot on Pandorabots (the bot must be registered locally via `pb-migrate add` first)')]
final class BotCreateCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('botname', InputArgument::REQUIRED, 'Name of the bot to create on Pandorabots');
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
                'bot "%s" is not registered locally. Run `pb-migrate add <directory> --bot %s` first.',
                $botname,
                $botname,
            ));
        }

        $client = $this->client($config);
        $client->create($botname);

        $io->success(sprintf('Created bot on Pandorabots: %s', $botname));
        return Command::SUCCESS;
    }
}
