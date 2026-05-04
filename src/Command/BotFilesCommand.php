<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Spontena\PbPhp\FileKind;
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
            'files' => ['title' => 'AIML', 'kind' => FileKind::File],
            'sets' => ['title' => 'Sets', 'kind' => FileKind::Set],
            'maps' => ['title' => 'Maps', 'kind' => FileKind::Map],
            'substitutions' => ['title' => 'Substitutions', 'kind' => FileKind::Substitution],
            'pdefaults' => ['title' => 'Pdefaults', 'kind' => FileKind::Pdefaults],
            'properties' => ['title' => 'Properties', 'kind' => FileKind::Properties],
        ];

        $printed = 0;
        foreach ($sections as $key => $section) {
            $entries = $response->{$key} ?? [];
            if (!is_array($entries) || $entries === []) {
                continue;
            }

            $kind = $section['kind'];
            $rows = [];
            foreach ($entries as $entry) {
                if (!$entry instanceof \stdClass) {
                    continue;
                }
                // For kinds without a filename component the API echoes the
                // kind value (`pdefaults`/`properties`) as the row label —
                // already shown in the section header, so render it as `—`
                // here rather than duplicating.
                $name = $kind->hasFilenameInPath()
                    ? (isset($entry->name) ? (string) $entry->name : '')
                    : '—';
                $rows[] = [
                    $name,
                    isset($entry->modified) ? (string) $entry->modified : '',
                ];
            }

            if ($rows === []) {
                continue;
            }

            $io->writeln('');
            $title = sprintf('%s (%d)', $section['title'], count($rows));
            $io->writeln($title);
            $io->writeln(str_repeat('-', strlen($title)));
            // The Pandorabots API consistently reports size=0 for every entry,
            // so the column was always noise. Drop it.
            $this->plainTable($io, ['name', 'modified'], $rows);
            $printed += count($rows);
        }

        if ($printed === 0) {
            $io->writeln('(no files)');
        } else {
            $io->writeln(sprintf('%d file(s) total', $printed));
        }

        return Command::SUCCESS;
    }
}
