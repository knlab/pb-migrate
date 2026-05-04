<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Repl;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

final class ReplLoop
{
    public function __construct(
        private readonly Application $app,
        private readonly HistoryStore $history,
    ) {
    }

    public function run(OutputInterface $output): int
    {
        if (!function_exists('readline')) {
            $output->writeln('<error>readline is not available; install ext-readline to use REPL.</error>');
            return 1;
        }

        $this->history->load();
        $this->installCompletion();
        $output->writeln(sprintf('<info>%s REPL — type "help", "exit", or Ctrl-D to quit.</info>', $this->app->getName()));

        while (true) {
            $line = readline('pb-migrate> ');
            if ($line === false) {
                $output->writeln('');
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (in_array($line, ['exit', 'quit', ':q'], true)) {
                break;
            }

            $this->history->append($line);

            try {
                $this->app->doRun(new StringInput(self::normaliseChatInput($line)), $output);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            }
        }

        $this->history->save();
        return 0;
    }

    private function installCompletion(): void
    {
        if (!function_exists('readline_completion_function')) {
            return;
        }

        $commands = [];
        foreach (array_keys($this->app->all()) as $name) {
            // Symfony exposes hidden helpers via `_` prefix; don't surface those.
            if (!str_starts_with($name, '_')) {
                $commands[] = $name;
            }
        }
        sort($commands);

        readline_completion_function(static function (string $partial) use ($commands): array {
            $info = function_exists('readline_info') ? readline_info() : [];
            $buffer = is_array($info) && isset($info['line_buffer']) ? (string) $info['line_buffer'] : '';
            $point = is_array($info) && isset($info['point']) ? (int) $info['point'] : strlen($buffer);
            $beforeCursor = substr($buffer, 0, $point);
            $partialStart = $point - strlen($partial);
            $headBeforePartial = substr($beforeCursor, 0, max(0, $partialStart));

            // First word on the line → complete command names.
            if (trim($headBeforePartial) === '') {
                $matches = [];
                foreach ($commands as $name) {
                    if ($partial === '' || str_starts_with($name, $partial)) {
                        $matches[] = $name;
                    }
                }
                return $matches !== [] ? $matches : [''];
            }

            // Anything else → filesystem path completion. glob() with GLOB_MARK
            // suffixes directories with `/` so the user can keep typing into
            // them after the tab completes.
            $pattern = ($partial === '' ? '' : $partial) . '*';
            $matches = glob($pattern, GLOB_MARK) ?: [];
            return $matches !== [] ? $matches : [''];
        });
    }

    /**
     * Quote multi-word positional input for the chat commands so users can
     * type `talk what is your name --bot foo` without manual quoting. If the
     * user already started the input with a quote we trust them and leave it
     * alone — same when the body is a single word.
     */
    public static function normaliseChatInput(string $line): string
    {
        if (preg_match('/^(talk|debug|atalk)\b(.*)$/', $line, $m) !== 1) {
            return $line;
        }
        $cmd = $m[1];
        $rest = ltrim($m[2]);

        if ($rest === '' || $rest[0] === '"' || $rest[0] === "'") {
            return $line;
        }

        // Split off the first flag (`\s+--\S`) so we only coalesce the
        // positional input portion. Quoted strings inside the body would be
        // returned via the early-exit above, so the simple split is safe here.
        if (preg_match('/^(.+?)(\s+--\S+.*)$/', $rest, $rm) === 1) {
            $body = trim($rm[1]);
            $tail = $rm[2];
        } else {
            $body = trim($rest);
            $tail = '';
        }

        if ($body === '' || !str_contains($body, ' ')) {
            return $line;
        }

        return sprintf('%s "%s"%s', $cmd, str_replace('"', '\\"', $body), $tail);
    }
}
