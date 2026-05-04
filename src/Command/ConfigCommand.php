<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\EnvFile;
use KnLab\PbMigrate\Config\ProjectConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'config', description: 'Edit project credentials (PB_APP_ID/PB_USER_KEY/PB_HOST) or per-bot bot_key in .env')]
final class ConfigCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to pb-migrate.json', ProjectConfig::DEFAULT_FILENAME);
        $this->addOption('bot', 'b', InputOption::VALUE_REQUIRED, 'Edit a bot_key for the named bot (instead of project credentials)');
        $this->addOption('show', null, InputOption::VALUE_NONE, 'Display current values (masked) without editing');
        $this->addOption('plain', null, InputOption::VALUE_NONE, 'When used with --show, print credentials in plain text instead of masked');
        $this->addOption('app-id', null, InputOption::VALUE_REQUIRED, 'Set PB_APP_ID non-interactively (skip prompt)');
        $this->addOption('user-key', null, InputOption::VALUE_REQUIRED, 'Set PB_USER_KEY non-interactively');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Set PB_HOST non-interactively');
        $this->addOption('bot-key', null, InputOption::VALUE_REQUIRED, 'Set PB_BOT_<NAME>_KEY non-interactively (used with --bot)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configPath = (string) $input->getOption('config');
        $projectRoot = $this->resolveProjectRoot($configPath);
        $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        $envFile = new EnvFile($envPath);

        // Show mode — read-only display
        if ($input->getOption('show')) {
            return $this->showAll($io, $envFile, (bool) $input->getOption('plain'));
        }

        $botname = (string) ($input->getOption('bot') ?? '');
        if ($botname !== '') {
            return $this->editBotKey($io, $input, $envFile, $botname);
        }

        return $this->editProjectCredentials($io, $input, $envFile);
    }

    private function editProjectCredentials(SymfonyStyle $io, InputInterface $input, EnvFile $envFile): int
    {
        $current = $envFile->readBlock(EnvFile::BLOCK_APP) ?? [];
        $appId = $current['PB_APP_ID'] ?? '';
        $userKey = $current['PB_USER_KEY'] ?? '';
        $host = $current['PB_HOST'] ?? '';

        // Non-interactive flag-driven mode
        $cliAppId = $input->getOption('app-id');
        $cliUserKey = $input->getOption('user-key');
        $cliHost = $input->getOption('host');
        $hasFlags = $cliAppId !== null || $cliUserKey !== null || $cliHost !== null;

        if ($hasFlags) {
            if (is_string($cliAppId)) {
                $appId = $cliAppId;
            }
            if (is_string($cliUserKey)) {
                $userKey = $cliUserKey;
            }
            if (is_string($cliHost)) {
                $host = $cliHost;
            }
        } else {
            // Interactive prompt
            $io->writeln('Editing project credentials in .env');
            $io->writeln(sprintf('  (current %s, Enter to keep)', $envFile->path));
            $io->writeln('');
            $appId = $this->promptWithDefault($io, 'PB_APP_ID', $appId);
            $userKey = $this->promptWithDefault($io, 'PB_USER_KEY', $userKey);
            $host = $this->promptWithDefault($io, 'PB_HOST (blank for default)', $host);
        }

        if ($appId === '' || $userKey === '') {
            throw new ConfigException('PB_APP_ID and PB_USER_KEY are both required.');
        }

        $vars = ['PB_APP_ID' => $appId, 'PB_USER_KEY' => $userKey];
        if ($host !== '') {
            $vars['PB_HOST'] = $host;
        }
        $envFile->writeBlock(EnvFile::BLOCK_APP, $vars);
        EnvFile::applyToProcess($vars);

        $io->success(sprintf('Updated %s', $envFile->path));
        return Command::SUCCESS;
    }

    private function editBotKey(SymfonyStyle $io, InputInterface $input, EnvFile $envFile, string $botname): int
    {
        $blockId = EnvFile::blockIdForBot($botname);
        $envName = EnvFile::envNameForBotKey($botname);
        $current = $envFile->readBlock($blockId) ?? [];
        $botKey = $current[$envName] ?? '';

        $cliBotKey = $input->getOption('bot-key');
        if (is_string($cliBotKey)) {
            $botKey = $cliBotKey;
        } else {
            $io->writeln(sprintf('Editing bot_key for "%s" in .env', $botname));
            $io->writeln('');
            $botKey = $this->promptWithDefault($io, $envName, $botKey, secret: true);
        }

        if ($botKey === '') {
            // Treat empty as removal
            if ($envFile->removeBlock($blockId)) {
                putenv($envName);
                unset($_ENV[$envName]);
                $io->success(sprintf('Removed bot_key for "%s"', $botname));
            } else {
                $io->writeln(sprintf('<comment>No bot_key was set for "%s"; nothing to do</comment>', $botname));
            }
            return Command::SUCCESS;
        }

        $vars = [$envName => $botKey];
        $envFile->writeBlock($blockId, $vars);
        EnvFile::applyToProcess($vars);

        $io->success(sprintf('Updated bot_key for "%s" in %s', $botname, $envFile->path));
        return Command::SUCCESS;
    }

    private function showAll(SymfonyStyle $io, EnvFile $envFile, bool $plain): int
    {
        $appBlock = $envFile->readBlock(EnvFile::BLOCK_APP) ?? [];

        $io->writeln('<info>Project credentials:</info>');
        $io->writeln(sprintf('  PB_APP_ID:   %s', $this->display($appBlock['PB_APP_ID'] ?? null, $plain)));
        $io->writeln(sprintf('  PB_USER_KEY: %s', $this->display($appBlock['PB_USER_KEY'] ?? null, $plain)));
        $host = $appBlock['PB_HOST'] ?? null;
        $io->writeln(sprintf('  PB_HOST:     %s', $host ?? '(default: ' . ProjectConfig::DEFAULT_HOST . ')'));

        $io->writeln('');
        $io->writeln('<info>Bot keys:</info>');
        $allBlocks = $envFile->listBlocks();
        $botBlocks = array_filter($allBlocks, static fn (string $id) => str_starts_with($id, 'bot='));
        if ($botBlocks === []) {
            $io->writeln('  <comment>(none configured)</comment>');
        } else {
            foreach ($botBlocks as $blockId) {
                $botname = substr($blockId, 4);
                $vars = $envFile->readBlock($blockId) ?? [];
                $envName = EnvFile::envNameForBotKey($botname);
                $io->writeln(sprintf('  %s: %s', $botname, $this->display($vars[$envName] ?? null, $plain)));
            }
        }
        return Command::SUCCESS;
    }

    private function display(?string $value, bool $plain): string
    {
        if ($value === null || $value === '') {
            return '<comment>(not set)</comment>';
        }
        if ($plain) {
            return $value;
        }
        return $this->mask($value);
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($value, -4);
    }

    private function promptWithDefault(SymfonyStyle $io, string $label, string $default, bool $secret = false): string
    {
        if ($default !== '') {
            $hint = $secret ? '(' . $this->mask($default) . ')' : '(' . $default . ')';
            $question = sprintf('%s %s [Enter to keep]', $label, $hint);
        } else {
            $question = $label;
        }
        $value = $io->ask($question, $default);
        return is_string($value) ? $value : $default;
    }

    private function resolveProjectRoot(string $configPath): string
    {
        $absolute = $configPath;
        if (!str_starts_with($absolute, '/')) {
            $cwd = getcwd() ?: '.';
            $absolute = $cwd . DIRECTORY_SEPARATOR . $absolute;
        }
        $real = realpath($absolute);
        if ($real !== false) {
            return dirname($real);
        }
        // Config doesn't exist yet — use cwd as project root
        return dirname($absolute);
    }
}
