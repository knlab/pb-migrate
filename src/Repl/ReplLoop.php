<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Repl;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

final class ReplLoop
{
    public function __construct(
        private readonly Application $app,
        private readonly HistoryStore $history,
    ) {
    }

    public function run(OutputInterface $output): int
    {
        if (!function_exists('readline')) {
            $output->writeln('<error>readline is not available; install ext-readline to use REPL.</error>');
            return 1;
        }

        $this->history->load();
        $output->writeln(sprintf('<info>%s REPL — type "help", "exit", or Ctrl-D to quit.</info>', $this->app->getName()));

        while (true) {
            $line = readline('pb-migrate> ');
            if ($line === false) {
                $output->writeln('');
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (in_array($line, ['exit', 'quit', ':q'], true)) {
                break;
            }

            $this->history->append($line);

            try {
                $this->app->doRun(new StringInput($line), $output);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            }
        }

        $this->history->save();
        return 0;
    }
}
