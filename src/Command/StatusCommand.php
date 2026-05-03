<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Config\BotMatcher;
use KnLab\PbMigrate\Config\ProjectConfig;
use KnLab\PbMigrate\Sync\CacheStore;
use KnLab\PbMigrate\Sync\FileScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'status', description: 'Show the local sync state of bots managed in pb-migrate.json (no API calls)')]
final class StatusCommand extends AbstractBotCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);

        // status defaults to --all when neither --bot nor --all is given.
        $bots = $this->resolveBotsOrAll($config, $input);

        $cache = CacheStore::forProjectRoot($config->projectRoot);
        $scanner = new FileScanner();

        foreach ($bots as $bot) {
            $this->renderBot($io, $config, $bot, $cache, $scanner);
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

    private function renderBot(SymfonyStyle $io, ProjectConfig $config, BotConfig $bot, CacheStore $cache, FileScanner $scanner): void
    {
        $local = $scanner->scan($bot);

        $adds = $updates = 0;
        foreach ($local as $f) {
            $cached = $cache->get($bot->name, $f->kind, $f->name);
            if ($cached === null) {
                $adds++;
            } elseif ($cached !== $f->hash) {
                $updates++;
            }
        }

        $localCount = count($local);

        $clean = $adds === 0 && $updates === 0;
        $statusColor = $clean ? 'green' : 'yellow';
        $statusLabel = $clean ? '✓ in sync (vs last push)' : sprintf('(+) %d add  (*) %d update', $adds, $updates);

        $io->writeln('');
        $io->writeln(sprintf('<info>%s</info>', $bot->name));
        $io->writeln(sprintf('  URL:       %s', $config->host));
        $io->writeln(sprintf('  BOT:       %s/%s', $config->appId, $bot->name));
        $io->writeln(sprintf('  directory: %s', $bot->directory));
        $io->writeln(sprintf('  files:     %d local', $localCount));
        $io->writeln(sprintf('  status:    <fg=%s>%s</>', $statusColor, $statusLabel));
    }
}
