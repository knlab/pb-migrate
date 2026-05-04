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

#[AsCommand(name: 'alter:unset', description: 'Remove a single persistent alter from a bot')]
final class AlterUnsetCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Alter name to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $bot = $this->resolveBot($config, $input);

        $name = (string) $input->getArgument('name');
        if ($name === '') {
            throw new ConfigException('alter:unset requires <name>');
        }

        if (!array_key_exists($name, $bot->alters)) {
            $io->writeln(sprintf('<comment>%s.%s is not set; nothing to do</comment>', $bot->name, $name));
            return Command::SUCCESS;
        }

        $alters = [];
        foreach ($bot->alters as $existingName => $absolutePath) {
            if ($existingName === $name) {
                continue;
            }
            $alters[$existingName] = $this->relativeIfInside($absolutePath, $config->projectRoot);
        }

        $configPath = (string) $input->getOption('config');
        ProjectConfig::saveAlters($configPath, $bot->name, $alters);

        $io->success(sprintf('Removed alter %s.%s', $bot->name, $name));
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
