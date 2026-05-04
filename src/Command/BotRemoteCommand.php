<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bot:remote', description: 'List bots on the Pandorabots account, annotated with local registration state')]
final class BotRemoteCommand extends AbstractBotCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);

        $remote = $client->getBotsList();

        $io->writeln('');
        $io->writeln(sprintf('Account: %s', $config->appId()));
        $io->writeln(sprintf('URL:     %s', $config->host()));
        $io->writeln('');

        $registered = array_keys($config->bots());

        // Render the remote-side list with annotations.
        if ($remote === []) {
            $io->writeln('No bots on the remote account.');
        } else {
            $io->writeln(sprintf('Remote bots (%d):', count($remote)));
            $rows = [];
            $remoteNames = [];
            foreach ($remote as $entry) {
                $name = isset($entry->botname) ? (string) $entry->botname : '';
                if ($name === '') {
                    continue;
                }
                $remoteNames[] = $name;
                $compiled = !empty($entry->compiled) ? 'compiled' : 'uncompiled';
                $tag = in_array($name, $registered, true) ? 'registered' : 'unmanaged';
                $rows[] = [$name, $compiled, $tag];
            }
            $this->plainTable($io, ['name', 'state', 'tag'], $rows);
        }

        // Show locally-registered bots that don't yet exist on the remote.
        $missing = array_values(array_diff($registered, $remoteNames ?? []));
        if ($missing !== []) {
            $io->writeln('');
            $io->writeln(sprintf('Registered but not on remote (%d):', count($missing)));
            foreach ($missing as $name) {
                $io->writeln(sprintf(
                    '  %s  <comment>(run `pb-migrate bot:create %s` to create)</comment>',
                    $name,
                    $name,
                ));
            }
        }

        return Command::SUCCESS;
    }
}
