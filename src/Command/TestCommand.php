<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use KnLab\PbMigrate\Config\BotConfig;
use Spontena\PbPhp\PBClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'test', description: 'Send inputs to a bot and assert responses (returns non-zero exit code on mismatch)')]
final class TestCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('input', null, InputOption::VALUE_REQUIRED, 'User input to send (use with --expect for an inline single test)');
        $this->addOption('expect', null, InputOption::VALUE_REQUIRED, 'Expected response (exact joined match across all bot responses)');
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Test cases from a file (one per line: <input>|<expected>)');
        $this->addOption('client-name', null, InputOption::VALUE_REQUIRED, 'client_name for the conversation (optional)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bots = $this->resolveBots($config, $input);

        $cases = $this->resolveCases($input);
        if ($cases === []) {
            $io->error('Provide either --input X --expect Y or --file path/to/tests.txt');
            return Command::FAILURE;
        }

        $clientName = (string) $input->getOption('client-name');

        $totalPass = 0;
        $totalFail = 0;
        foreach ($bots as $bot) {
            $io->writeln(sprintf('<info>%s</info>:', $bot->name));
            foreach ($cases as $i => [$inputText, $expected]) {
                $passed = $this->runOne($io, $client, $bot, $inputText, $expected, $clientName);
                if ($passed) {
                    $totalPass++;
                } else {
                    $totalFail++;
                }
            }
        }

        if ($totalFail === 0) {
            $io->success(sprintf('All %d test(s) passed across %d bot(s)', $totalPass, count($bots)));
            return Command::SUCCESS;
        }
        $io->error(sprintf('%d/%d failed', $totalFail, $totalPass + $totalFail));
        return Command::FAILURE;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function resolveCases(InputInterface $input): array
    {
        $file = $input->getOption('file');
        if (is_string($file) && $file !== '') {
            return $this->loadCasesFile($file);
        }

        $inputText = $input->getOption('input');
        $expected = $input->getOption('expect');
        if (is_string($inputText) && $inputText !== '' && is_string($expected)) {
            return [[$inputText, $expected]];
        }

        return [];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function loadCasesFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Test file not found: %s', $path));
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Cannot read test file: %s', $path));
        }

        $cases = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (!str_contains($trimmed, '|')) {
                throw new \RuntimeException(sprintf('Test file line %d missing "|" separator: %s', $lineNumber + 1, $trimmed));
            }
            [$inputText, $expected] = explode('|', $trimmed, 2);
            $cases[] = [trim($inputText), trim($expected)];
        }
        return $cases;
    }

    private function runOne(
        \Symfony\Component\Console\Style\SymfonyStyle $io,
        PBClient $client,
        BotConfig $bot,
        string $inputText,
        string $expected,
        string $clientName,
    ): bool {
        try {
            $reply = $client->talk(
                input: $inputText,
                botname: $bot->name,
                clientName: $clientName,
            );
        } catch (\Throwable $e) {
            $io->writeln(sprintf('  <fg=red>FAIL</> "%s" — %s', $inputText, $e->getMessage()));
            return false;
        }

        $responses = $reply->responses ?? [];
        $actual = is_array($responses) ? implode(' ', array_map('strval', $responses)) : '';

        if ($actual === $expected) {
            $io->writeln(sprintf('  <fg=green>PASS</> "%s" → "%s"', $inputText, $actual));
            return true;
        }

        $io->writeln(sprintf('  <fg=red>FAIL</> "%s"', $inputText));
        $io->writeln(sprintf('    expected: %s', $expected));
        $io->writeln(sprintf('    actual:   %s', $actual));
        return false;
    }
}
