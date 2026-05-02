<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Repl\HistoryStore;
use KnLab\PbMigrate\Repl\ReplLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'repl', description: 'Start an interactive REPL shell (default when run without arguments)')]
final class ReplCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln('<error>REPL requires an application context.</error>');
            return Command::FAILURE;
        }

        $loop = new ReplLoop($app, HistoryStore::default());
        return $loop->run($output);
    }
}
