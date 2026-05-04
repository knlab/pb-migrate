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
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'test', description: 'Send inputs to a bot and assert responses (silent on success; non-zero exit on mismatch)')]
final class TestCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('input', null, InputOption::VALUE_REQUIRED, 'User input to send (use with --expect for an inline single test)');
        $this->addOption('expect', null, InputOption::VALUE_REQUIRED, 'Expected response (exact joined match across all bot responses)');
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Test cases from a file (one per line: <input>|<expected>; \\| escapes a literal pipe, \\\\ escapes a backslash; comments start with #)');
        $this->addOption('client-name', null, InputOption::VALUE_REQUIRED, 'client_name for the conversation (optional)', '');
        $this->addOption('show-pass', null, InputOption::VALUE_NONE, 'Print every PASS as well as FAIL (default mode is silent on success)');
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
        $verbose = (bool) $input->getOption('show-pass');

        $totalPass = 0;
        $totalFail = 0;
        foreach ($bots as $bot) {
            foreach ($cases as [$inputText, $expected]) {
                $passed = $this->runOne($io, $client, $bot, $inputText, $expected, $clientName, $verbose);
                if ($passed) {
                    $totalPass++;
                } else {
                    $totalFail++;
                }
            }
        }

        if ($totalFail === 0) {
            $io->writeln('');
            $io->success(sprintf('All %d test(s) passed across %d bot(s)', $totalPass, count($bots)));
            return Command::SUCCESS;
        }

        $io->writeln('');
        $io->writeln(sprintf(
            '<fg=yellow>%d/%d failed</>',
            $totalFail,
            $totalPass + $totalFail,
        ));
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
            $split = self::splitOnUnescapedPipe($trimmed);
            if ($split === null) {
                throw new \RuntimeException(sprintf('Test file line %d missing "|" separator: %s', $lineNumber + 1, $trimmed));
            }
            [$inputText, $expected] = $split;
            $inputText = trim(self::unescape($inputText));
            $expected = trim(self::unescape($expected));
            if ($inputText === '') {
                throw new \RuntimeException(sprintf('Test file line %d has empty input', $lineNumber + 1));
            }
            $cases[] = [$inputText, $expected];
        }
        return $cases;
    }

    /**
     * Split a line on the first unescaped pipe (`|`). A pipe preceded by an
     * odd number of backslashes is treated as escaped (literal). Returns
     * [input, expected], or null if no unescaped pipe is found.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function splitOnUnescapedPipe(string $line): ?array
    {
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] === '|') {
                // Count consecutive backslashes immediately before this pipe.
                $backslashes = 0;
                $j = $i - 1;
                while ($j >= 0 && $line[$j] === '\\') {
                    $backslashes++;
                    $j--;
                }
                if ($backslashes % 2 === 0) {
                    return [substr($line, 0, $i), substr($line, $i + 1)];
                }
            }
        }
        return null;
    }

    /**
     * Resolve `\|` → `|` and `\\` → `\`. Other backslash sequences are left
     * untouched (the backslash is preserved).
     */
    private static function unescape(string $value): string
    {
        $out = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            if ($value[$i] === '\\' && $i + 1 < $len) {
                $next = $value[$i + 1];
                if ($next === '|' || $next === '\\') {
                    $out .= $next;
                    $i++;
                    continue;
                }
            }
            $out .= $value[$i];
        }
        return $out;
    }

    private function runOne(
        SymfonyStyle $io,
        PBClient $client,
        BotConfig $bot,
        string $inputText,
        string $expected,
        string $clientName,
        bool $verbose,
    ): bool {
        try {
            $reply = $client->talk(
                input: $inputText,
                botname: $bot->name,
                clientName: $clientName,
            );
        } catch (\Throwable $e) {
            $io->writeln(sprintf('<fg=yellow>FAIL</> %s "%s" — %s', $bot->name, $inputText, $e->getMessage()));
            return false;
        }

        $responses = $reply->responses ?? [];
        $actual = is_array($responses) ? implode(' ', array_map('strval', $responses)) : '';

        if ($actual === $expected) {
            if ($verbose) {
                $io->writeln(sprintf('<fg=green>PASS</> %s "%s" → "%s"', $bot->name, $inputText, $actual));
            }
            return true;
        }

        $io->writeln(sprintf('<fg=yellow>FAIL</> %s "%s"', $bot->name, $inputText));
        $io->writeln(sprintf('  expected: %s', $expected));
        $io->writeln(sprintf('  actual:   %s', $actual));
        return false;
    }
}
