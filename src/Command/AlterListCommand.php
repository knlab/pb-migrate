<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'alter:list', description: 'List persistent alters (file-body overrides) configured for one or more bots')]
final class AlterListCommand extends AbstractBotCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $bots = $this->resolveBots($config, $input);

        $printed = false;
        foreach ($bots as $bot) {
            $printed = true;
            $this->renderBot($io, $bot, $config->projectRoot);
        }

        if (!$printed) {
            $io->writeln('<comment>(no bots matched)</comment>');
        }
        return Command::SUCCESS;
    }

    private function renderBot(SymfonyStyle $io, BotConfig $bot, string $projectRoot): void
    {
        $io->section($bot->name);
        if ($bot->alters === []) {
            $io->writeln('  <comment>(no alters)</comment>');
            return;
        }

        $rows = [];
        foreach ($bot->alters as $name => $absolutePath) {
            $display = $absolutePath;
            if (str_starts_with($absolutePath, $projectRoot . DIRECTORY_SEPARATOR)) {
                $display = substr($absolutePath, strlen($projectRoot) + 1);
            }
            $rows[] = [$name, $display];
        }
        $io->table(['name', 'override path'], $rows);
    }
}
