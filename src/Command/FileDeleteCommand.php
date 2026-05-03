<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Exception\ConfigException;
use KnLab\PbMigrate\Sync\CacheStore;
use Spontena\PbPhp\FileKind;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'file:delete', description: 'Delete a single remote file (surgical removal, distinct from push --prune)')]
final class FileDeleteCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::OPTIONAL, 'File name (omit for kinds without a name in the URL: pdefaults / properties)');
        $this->addOption('kind', 'k', InputOption::VALUE_REQUIRED, 'File kind: file, set, map, substitution, pdefaults, properties');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
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

        $label = $kind->hasFilenameInPath()
            ? sprintf('%s/%s on bot %s', $kind->value, $name, $bot->name)
            : sprintf('%s on bot %s', $kind->value, $bot->name);

        if (!$input->getOption('yes')) {
            $confirmed = $io->confirm(sprintf('Really delete %s? This cannot be undone.', $label), false);
            if (!$confirmed) {
                $io->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        // Workaround for spontena/pb-php v2.1.0: deleteBotFile asserts fname
        // is non-empty even when the URL ignores it (pdefaults / properties).
        // Pass the kind value as a harmless placeholder; the URL builder drops
        // it because FileKind::hasFilenameInPath() returns false for those kinds.
        $fnameForApi = $name ?? $kind->value;

        $client->deleteBotFile(
            fname: $fnameForApi,
            fkind: $kind,
            botname: $bot->name,
        );

        // Keep the cache in sync so the next push doesn't try to "restore" what we just deleted.
        $cache = CacheStore::forProjectRoot($config->projectRoot);
        $cache->forget($bot->name, $kind, $name ?? '');
        $cache->save();

        $io->success(sprintf('Deleted %s', $label));
        return Command::SUCCESS;
    }
}
