<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Repl\RunbookParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'batch', description: 'Run a runbook file: one command per line. Comments (#) and blank lines are skipped.')]
final class BatchCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the runbook file');
        $this->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Keep running subsequent commands even if one fails (default: stop on first failure)');
        $this->addOption('echo', null, InputOption::VALUE_NONE, 'Print each command to stdout before executing (useful for CI logs and audit trails)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln('<error>batch requires an application context.</error>');
            return Command::FAILURE;
        }

        $file = (string) $input->getArgument('file');
        $continueOnError = (bool) $input->getOption('continue-on-error');
        $echo = (bool) $input->getOption('echo');

        try {
            $commands = RunbookParser::parseFile($file);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        if ($commands === []) {
            $output->writeln(sprintf('<comment>(no commands in %s)</comment>', $file));
            return Command::SUCCESS;
        }

        $failed = 0;
        $ran = 0;
        foreach ($commands as $line) {
            if ($echo) {
                $output->writeln(sprintf('<info>$ %s</info>', $line));
            }
            try {
                $exit = $app->doRun(new StringInput($line), $output);
                $ran++;
                if ($exit !== Command::SUCCESS) {
                    $failed++;
                    if (!$continueOnError) {
                        $output->writeln(sprintf('<error>batch: command failed (exit %d): %s</error>', $exit, $line));
                        return $exit;
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf('<error>batch: %s</error>', $e->getMessage()));
                if (!$continueOnError) {
                    return Command::FAILURE;
                }
            }
        }

        if ($failed === 0) {
            $output->writeln(sprintf('<info>batch: %d/%d ok</info>', $ran, count($commands)));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<comment>batch: %d/%d ok, %d failed (continue-on-error)</comment>', $ran - $failed, count($commands), $failed));
        return Command::FAILURE;
    }
}
