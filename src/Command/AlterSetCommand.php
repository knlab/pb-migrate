<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\ProjectConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'alter:set', description: 'Add or update a persistent alter (file-body override) for a bot')]
final class AlterSetCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'File name to alter (e.g. "greet" or "_dump_predicates")');
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the override file (kind is inferred from its extension)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $bot = $this->resolveBot($config, $input);

        $name = (string) $input->getArgument('name');
        $userPath = (string) $input->getArgument('path');
        if ($name === '' || $userPath === '') {
            throw new ConfigException('alter:set requires both <name> and <path>');
        }

        $stored = ProjectConfig::normalizeAlterPath($userPath, $config->projectRoot);

        $alters = [];
        foreach ($bot->alters as $existingName => $absolutePath) {
            $alters[$existingName] = $this->relativeIfInside($absolutePath, $config->projectRoot);
        }
        $alters[$name] = $stored;

        $configPath = (string) $input->getOption('config');
        ProjectConfig::saveAlters($configPath, $bot->name, $alters);

        $io->success(sprintf('Set alter %s.%s → %s', $bot->name, $name, $stored));
        return Command::SUCCESS;
    }

    private function relativeIfInside(string $absolute, string $projectRoot): string
    {
        if (str_starts_with($absolute, $projectRoot . DIRECTORY_SEPARATOR)) {
            return substr($absolute, strlen($projectRoot) + 1);
        }
        return $absolute;
    }
}
