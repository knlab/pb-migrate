<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\ProjectConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'add', description: 'Register an existing AIML package directory as a bot in pb-migrate.json')]
final class AddCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'Path to the existing AIML package directory');
        $this->addOption('bot', 'b', InputOption::VALUE_REQUIRED, 'Bot name (alphanumeric only). Defaults to the directory basename.');
        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to pb-migrate.json', ProjectConfig::DEFAULT_FILENAME);
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing registration without prompting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configPath = (string) $input->getOption('config');
        $directory = (string) $input->getArgument('directory');
        if ($directory === '') {
            throw new ConfigException('directory is required');
        }

        $absoluteDirs = $this->resolveDirectories($directory);

        $explicitBotname = (string) ($input->getOption('bot') ?? '');
        if ($explicitBotname !== '' && count($absoluteDirs) > 1) {
            throw new ConfigException(sprintf(
                '--bot cannot be used with a pattern that matches multiple directories (matched %d).',
                count($absoluteDirs),
            ));
        }

        $absoluteConfig = $configPath;
        if (!str_starts_with($absoluteConfig, '/')) {
            $cwd = getcwd() ?: '.';
            $absoluteConfig = $cwd . DIRECTORY_SEPARATOR . $absoluteConfig;
        }

        $force = (bool) $input->getOption('force');
        $projectRoot = dirname(realpath($absoluteConfig) ?: $absoluteConfig);
        $registered = 0;

        foreach ($absoluteDirs as $absoluteDir) {
            $botname = $explicitBotname !== '' ? $explicitBotname : basename($absoluteDir);
            $this->assertBotnameValid($botname);

            if (is_file($absoluteConfig)) {
                $existing = ProjectConfig::load($absoluteConfig);
                if ($existing->hasBot($botname) && !$force) {
                    $output->writeln(sprintf(
                        '<error>bot "%s" is already registered. Use --force to overwrite.</error>',
                        $botname,
                    ));
                    if (count($absoluteDirs) === 1) {
                        return Command::FAILURE;
                    }
                    // For multi-match: skip and continue with the rest.
                    continue;
                }
            }

            $storedDirectory = $this->relativeIfInside($absoluteDir, $projectRoot);
            ProjectConfig::saveBot($absoluteConfig, $botname, [
                'directory' => $storedDirectory,
            ]);

            $output->writeln(sprintf(
                '<info>Registered bot "%s"</info> → %s',
                $botname,
                $storedDirectory,
            ));
            $registered++;
        }

        if ($registered === 0) {
            return Command::FAILURE;
        }

        // Helpful next-step hint shown only once even if multiple bots were registered.
        $tmpConfig = ProjectConfig::load($absoluteConfig);
        if (!$tmpConfig->hasCredentials()) {
            $output->writeln('');
            $output->writeln('<comment>Next: set project credentials with `pb-migrate config`</comment>');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string> absolute directory paths
     */
    private function resolveDirectories(string $userInput): array
    {
        // Glob expansion: if the input contains a wildcard, expand it. Otherwise
        // treat it as a literal path. A wildcard with zero matches is an error.
        if (preg_match('/[*?\[]/', $userInput) === 1) {
            $pattern = $userInput;
            if (!str_starts_with($pattern, '/')) {
                $cwd = getcwd() ?: '.';
                $pattern = $cwd . DIRECTORY_SEPARATOR . $pattern;
            }
            $matches = glob($pattern, GLOB_ONLYDIR) ?: [];
            if ($matches === []) {
                throw new ConfigException(sprintf('No directories match: %s', $userInput));
            }
            $resolved = [];
            foreach ($matches as $match) {
                $real = realpath($match);
                if ($real !== false && is_dir($real)) {
                    $resolved[] = $real;
                }
            }
            sort($resolved);
            return $resolved;
        }

        $absolute = $userInput;
        if (!str_starts_with($absolute, '/')) {
            $cwd = getcwd() ?: '.';
            $absolute = $cwd . DIRECTORY_SEPARATOR . $absolute;
        }
        $real = realpath($absolute);
        if ($real === false) {
            throw new ConfigException(sprintf('Directory does not exist: %s', $userInput));
        }
        if (!is_dir($real)) {
            throw new ConfigException(sprintf('Not a directory: %s', $userInput));
        }
        return [$real];
    }

    private function assertBotnameValid(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $name) !== 1) {
            throw new ConfigException(sprintf(
                'Invalid bot name "%s": Pandorabots requires alphanumeric only ([A-Za-z0-9]+).',
                $name,
            ));
        }
    }

    private function relativeIfInside(string $absolute, string $projectRoot): string
    {
        $projectReal = realpath($projectRoot);
        if ($projectReal !== false && str_starts_with($absolute, $projectReal . DIRECTORY_SEPARATOR)) {
            return './' . substr($absolute, strlen($projectReal) + 1);
        }
        return $absolute;
    }
}
