<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Config\BotMatcher;
use KnLab\PbMigrate\Config\ProjectConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'alter:list', description: 'List persistent alters (file-body overrides) configured for one or more bots. Defaults to --all when no selector is given.')]
final class AlterListCommand extends AbstractBotCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);

        // Default to --all when neither --bot nor --all is given.
        $bots = $this->resolveBotsOrAll($config, $input);

        if ($bots === []) {
            $io->writeln('<comment>(no bots registered)</comment>');
            return Command::SUCCESS;
        }

        foreach ($bots as $bot) {
            $this->renderBot($io, $bot, $config->projectRoot);
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<BotConfig>
     */
    private function resolveBotsOrAll(ProjectConfig $config, InputInterface $input): array
    {
        if ($input->getOption('all')) {
            return BotMatcher::all($config);
        }
        $selector = (string) $input->getOption('bot');
        if ($selector === '') {
            return BotMatcher::all($config);
        }
        return BotMatcher::resolve($config, $selector);
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
            $missing = !is_file($absolutePath);
            $marker = $missing ? '<fg=red>[missing!]</>' : '';
            $rows[] = [$name, $display, $marker];
        }
        $io->table(['name', 'override path', 'status'], $rows);
    }
}
