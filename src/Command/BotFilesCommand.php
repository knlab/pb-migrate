<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bot:files', description: 'List the files stored on a single bot, grouped by kind')]
final class BotFilesCommand extends AbstractBotCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bot = $this->resolveBot($config, $input);

        $response = $client->getBotFiles($bot->name);

        $sections = [
            'files' => 'AIML',
            'sets' => 'Sets',
            'maps' => 'Maps',
            'substitutions' => 'Substitutions',
            'pdefaults' => 'Pdefaults',
            'properties' => 'Properties',
        ];

        $printed = 0;
        foreach ($sections as $key => $title) {
            $entries = $response->{$key} ?? [];
            if (!is_array($entries) || $entries === []) {
                continue;
            }

            $rows = [];
            foreach ($entries as $entry) {
                if (!$entry instanceof \stdClass) {
                    continue;
                }
                $rows[] = [
                    isset($entry->name) ? (string) $entry->name : '',
                    isset($entry->size) ? (string) $entry->size : '',
                    isset($entry->modified) ? (string) $entry->modified : '',
                ];
            }

            if ($rows === []) {
                continue;
            }

            $io->section(sprintf('%s (%d)', $title, count($rows)));
            $io->table(['name', 'size', 'modified'], $rows);
            $printed += count($rows);
        }

        if ($printed === 0) {
            $io->writeln('<comment>(no files)</comment>');
        } else {
            $io->writeln(sprintf('<comment>%d file(s) total</comment>', $printed));
        }

        return Command::SUCCESS;
    }
}
