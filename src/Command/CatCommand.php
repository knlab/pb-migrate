<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Exception\ConfigException;
use Spontena\PbPhp\FileKind;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cat', description: 'Print a single remote file body to stdout (pipeable, redirectable)')]
final class CatCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::OPTIONAL, 'File name (omit for kinds without a name in the URL: pdefaults / properties)');
        $this->addOption('kind', 'k', InputOption::VALUE_REQUIRED, 'File kind: file, set, map, substitution, pdefaults, properties');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bot = $this->resolveBot($config, $input);

        $kindRaw = (string) ($input->getOption('kind') ?? '');
        if ($kindRaw === '') {
            throw new ConfigException('--kind is required');
        }
        $kind = FileKind::tryFrom(strtolower($kindRaw));
        if ($kind === null) {
            throw new ConfigException(sprintf('Unknown kind "%s"; valid: file, set, map, substitution, pdefaults, properties', $kindRaw));
        }

        $name = $input->getArgument('name');
        $name = is_string($name) && $name !== '' ? $name : null;

        if ($kind->hasFilenameInPath() && $name === null) {
            throw new ConfigException(sprintf('kind "%s" requires a name argument', $kind->value));
        }
        if (!$kind->hasFilenameInPath() && $name !== null) {
            throw new ConfigException(sprintf('kind "%s" must not be given a name argument', $kind->value));
        }

        $body = $client->getBotFile(kind: $kind, botname: $bot->name, name: $name);

        // Use raw write so the output is byte-faithful and pipe-friendly.
        $output->write($body, false, OutputInterface::OUTPUT_RAW);
        if (!str_ends_with($body, "\n")) {
            $output->write("\n", false, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
