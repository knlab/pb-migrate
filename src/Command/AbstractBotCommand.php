<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Config\ProjectConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use KnLab\PbMigrate\PBClientFactory;
use Spontena\PbPhp\PBClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractBotCommand extends Command
{
    private ?PBClientFactory $factory = null;

    public function setFactory(PBClientFactory $factory): void
    {
        $this->factory = $factory;
    }

    protected function configure(): void
    {
        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to pb-migrate.json', ProjectConfig::DEFAULT_FILENAME);
        $this->addOption('bot', 'b', InputOption::VALUE_REQUIRED, 'Bot name (must be defined in pb-migrate.json)');
    }

    protected function loadConfig(InputInterface $input): ProjectConfig
    {
        $path = (string) $input->getOption('config');
        return ProjectConfig::load($path);
    }

    protected function resolveBot(ProjectConfig $config, InputInterface $input): BotConfig
    {
        $name = (string) $input->getOption('bot');
        if ($name === '') {
            throw new ConfigException('--bot is required');
        }
        return $config->bot($name);
    }

    protected function client(ProjectConfig $config): PBClient
    {
        $factory = $this->factory ?? new PBClientFactory();
        return $factory->forConfig($config);
    }

    protected function style(InputInterface $input, OutputInterface $output): SymfonyStyle
    {
        return new SymfonyStyle($input, $output);
    }
}
