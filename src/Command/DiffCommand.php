<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Sync\BotSync;
use KnLab\PbMigrate\Sync\DiffEngine;
use KnLab\PbMigrate\Sync\FileChange;
use KnLab\PbMigrate\Sync\FileScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'diff', description: 'Show unified diff between local and remote bot files')]
final class DiffCommand extends AbstractBotCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bot = $this->resolveBot($config, $input);

        $diff = new DiffEngine();
        $sync = new BotSync($client, new FileScanner(), $diff);
        $changes = $sync->plan($bot);

        if ($changes->isEmpty()) {
            $io->writeln('<info>(no differences)</info>');
            return Command::SUCCESS;
        }

        foreach ($changes->all() as $change) {
            $label = $change->kind->value . '/' . $change->name;
            switch ($change->action) {
                case FileChange::ADD:
                    $io->writeln(sprintf('<fg=green># local-only: %s</>', $label));
                    break;
                case FileChange::DELETE:
                    $io->writeln(sprintf('<fg=red># remote-only: %s</>', $label));
                    break;
                case FileChange::UPDATE:
                    if ($change->localPath === null) {
                        continue 2;
                    }
                    $localContent = (string) file_get_contents($change->localPath);
                    $remoteContent = $client->getBotFile(
                        kind: $change->kind,
                        botname: $bot->name,
                        name: $change->kind->hasFilenameInPath() ? $change->name : null,
                    );
                    $io->writeln($diff->unified($localContent, $remoteContent, $label));
                    break;
            }
        }

        return Command::SUCCESS;
    }
}
