<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Config\BotMatcher;
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
        $this->addOption('bot', 'b', InputOption::VALUE_REQUIRED, 'Bot name or glob pattern (e.g. "mybot", "prod.*")');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Apply to every bot defined in pb-migrate.json');
    }

    protected function loadConfig(InputInterface $input): ProjectConfig
    {
        $path = (string) $input->getOption('config');
        return ProjectConfig::load($path);
    }

    /**
     * Resolve a single bot. Errors if --all is set or no --bot is given.
     */
    protected function resolveBot(ProjectConfig $config, InputInterface $input): BotConfig
    {
        if ($input->getOption('all')) {
            throw new ConfigException('This command operates on a single bot; --all is not supported here.');
        }
        $name = (string) $input->getOption('bot');
        if ($name === '') {
            throw new ConfigException('--bot is required');
        }
        if (BotMatcher::looksLikePattern($name)) {
            throw new ConfigException(sprintf('"%s" looks like a pattern; this command requires an exact bot name.', $name));
        }
        return $config->bot($name);
    }

    /**
     * Resolve one or more bots. --all expands to every bot, --bot accepts a
     * single name or a glob pattern.
     *
     * @return list<BotConfig>
     */
    protected function resolveBots(ProjectConfig $config, InputInterface $input): array
    {
        if ($input->getOption('all')) {
            return BotMatcher::all($config);
        }
        $selector = (string) $input->getOption('bot');
        if ($selector === '') {
            throw new ConfigException('Either --bot <name|pattern> or --all is required');
        }
        return BotMatcher::resolve($config, $selector);
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
