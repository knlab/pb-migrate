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
        $output->writeln(sprintf('%s REPL — type "?" or "help" to list commands, "exit" or Ctrl-D to quit.', $this->app->getName()));

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

            // Shell escape: `! cmd` runs cmd in /bin/sh and continues. Useful
            // during dogfooding loops where edits / inspections need a shell
            // (psql / sqlite / ipython all support this). Pass-through to the
            // user's shell so pipes / redirection / glob all just work.
            if (str_starts_with($line, '!')) {
                $cmd = trim(substr($line, 1));
                if ($cmd !== '') {
                    passthru($cmd);
                }
                continue;
            }

            // REPL aliases for "show me what I can run":
            //   ?           → list      (psql / sqlite-style shortcut)
            //   help        → list      (Symfony's bare `help` shows
            //                             help-for-help, useless in a REPL)
            //   ? <command> → help <command>
            //   help <X>    → unchanged, delegates to Symfony's help command
            $resolved = $line;
            if ($line === '?' || $line === 'help') {
                $resolved = 'list';
            } elseif (str_starts_with($line, '? ')) {
                $resolved = 'help ' . substr($line, 2);
            }

            try {
                $this->app->doRun(new StringInput(self::normaliseChatInput($resolved)), $output);
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
                return self::completionResult($matches);
            }

            // Anything else → filesystem path completion. glob() with GLOB_MARK
            // suffixes directories with `/` so the user can keep typing into
            // them after the tab completes.
            $pattern = ($partial === '' ? '' : $partial) . '*';
            $matches = glob($pattern, GLOB_MARK) ?: [];
            return self::completionResult($matches);
        });
    }

    /**
     * libedit (which macOS PHP links against) appends a trailing space after
     * every unambiguous completion, even when the match is a directory ending
     * in `/` — and it doesn't honour the `completion_suppress_append` flag
     * that libreadline does. The standard libedit-compatible workaround is
     * to add a phantom second candidate so libedit treats the result as
     * ambiguous, completes only to the common prefix, and skips the space.
     * The phantom uses a NUL terminator so most terminals don't render any
     * visible second entry on a follow-up tab.
     *
     * @param list<string> $matches
     * @return list<string>
     */
    private static function completionResult(array $matches): array
    {
        if ($matches === []) {
            return [''];
        }
        if (count($matches) === 1 && str_ends_with($matches[0], '/')) {
            return [$matches[0], $matches[0] . "\0"];
        }
        return $matches;
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
