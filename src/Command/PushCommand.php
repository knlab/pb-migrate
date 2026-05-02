<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Sync\BotSync;
use KnLab\PbMigrate\Sync\DiffEngine;
use KnLab\PbMigrate\Sync\FileScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'push', description: 'Push local AIML files to a bot (add/update/delete) and compile')]
final class PushCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without uploading');
        $this->addOption('skip-compile', null, InputOption::VALUE_NONE, 'Do not run compile after upload');
        $this->addOption('prune', null, InputOption::VALUE_NONE, 'Delete remote files that are missing locally (off by default — additive sync)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bot = $this->resolveBot($config, $input);

        $sync = new BotSync($client, new FileScanner(), new DiffEngine());
        $changes = $sync->plan($bot);

        if ($changes->isEmpty()) {
            $io->success(sprintf('No changes for bot "%s"', $bot->name));
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Push plan for bot <info>%s</info>:', $bot->name));
        if ($input->getOption('dry-run')) {
            foreach ($changes->all() as $change) {
                $io->writeln(sprintf('  [%s] %s/%s', $change->action, $change->kind->value, $change->name));
            }
            $io->writeln('<comment>(dry run — no API calls made)</comment>');
            return Command::SUCCESS;
        }

        $sync->applyPush($bot, $changes, $io, prune: (bool) $input->getOption('prune'));

        if ($input->getOption('skip-compile')) {
            $io->note('Skipped compile (--skip-compile)');
        } else {
            $sync->compile($bot, $io);
        }

        $io->success(sprintf('Pushed %d change(s) to bot "%s"', $changes->count(), $bot->name));
        return Command::SUCCESS;
    }
}
