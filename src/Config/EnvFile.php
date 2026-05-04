<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Config;

use KnLab\PbMigrate\Exception\ConfigException;

/**
 * Tool-managed `.env` reader/writer with block markers.
 *
 * Format:
 *   # pb-migrate:begin <id>
 *   KEY=value
 *   ...
 *   # pb-migrate:end <id>
 *
 * Block IDs we recognise:
 *   - `app`            — project-level credentials (PB_APP_ID / PB_USER_KEY / PB_HOST)
 *   - `bot=<botname>`  — per-bot bot_key (PB_BOT_<UPPER_BOTNAME>_KEY)
 *
 * Lines outside any block are user-managed free territory; we preserve them
 * untouched on every write. This lets the user keep their own env vars in the
 * same `.env` file alongside pb-migrate's tool-managed sections.
 */
final class EnvFile
{
    public const BLOCK_APP = 'app';

    public function __construct(public readonly string $path)
    {
    }

    public static function blockIdForBot(string $botname): string
    {
        return 'bot=' . $botname;
    }

    public static function envNameForBotKey(string $botname): string
    {
        return 'PB_BOT_' . strtoupper($botname) . '_KEY';
    }

    /**
     * Read the variables defined inside a managed block.
     *
     * @return array<string, string>|null Map of key => value, or null if the block does not exist
     */
    public function readBlock(string $blockId): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $lines = $this->readLines();
        $range = $this->findBlock($lines, $blockId);
        if ($range === null) {
            return null;
        }
        [$start, $end] = $range;
        $vars = [];
        for ($i = $start + 1; $i < $end; $i++) {
            $parsed = self::parseLine($lines[$i]);
            if ($parsed !== null) {
                $vars[$parsed[0]] = $parsed[1];
            }
        }
        return $vars;
    }

    /**
     * Write or replace a managed block. If the block exists its contents
     * are replaced; otherwise it is appended (separated from existing
     * content by a blank line).
     *
     * @param array<string, string> $vars Map of key => value to render inside the block
     */
    public function writeBlock(string $blockId, array $vars): void
    {
        $lines = is_file($this->path) ? $this->readLines() : [];

        $rendered = $this->renderBlockLines($blockId, $vars);
        $range = $this->findBlock($lines, $blockId);

        if ($range !== null) {
            [$start, $end] = $range;
            array_splice($lines, $start, $end - $start + 1, $rendered);
        } else {
            if ($lines !== [] && end($lines) !== '') {
                $lines[] = '';
            }
            $lines = array_merge($lines, $rendered);
        }

        $this->writeLines($lines);
    }

    /**
     * Remove a managed block, including any redundant trailing blank line
     * that becomes orphaned after removal.
     *
     * @return bool true if the block existed and was removed, false otherwise
     */
    public function removeBlock(string $blockId): bool
    {
        if (!is_file($this->path)) {
            return false;
        }
        $lines = $this->readLines();
        $range = $this->findBlock($lines, $blockId);
        if ($range === null) {
            return false;
        }
        [$start, $end] = $range;
        $removeCount = $end - $start + 1;
        // Also consume one trailing blank line if it exists, to avoid stacking
        // blank lines as blocks come and go.
        if (isset($lines[$end + 1]) && $lines[$end + 1] === '') {
            $removeCount++;
        }
        array_splice($lines, $start, $removeCount);
        $this->writeLines($lines);
        return true;
    }

    /**
     * @return list<string> List of all managed block IDs in the file (in order)
     */
    public function listBlocks(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $lines = $this->readLines();
        $ids = [];
        foreach ($lines as $line) {
            $id = self::matchBeginMarker($line);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Apply a block's vars to the running process via putenv() so subsequent
     * getenv() calls see the new values within the same PHP run.
     *
     * @param array<string, string> $vars
     */
    public static function applyToProcess(array $vars): void
    {
        foreach ($vars as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    /**
     * Find a block by its ID. Returns [startLineIdx, endLineIdx] (both
     * inclusive, pointing at the begin/end marker lines), or null if not
     * found.
     *
     * @param list<string> $lines
     * @return array{0: int, 1: int}|null
     */
    private function findBlock(array $lines, string $blockId): ?array
    {
        $start = null;
        foreach ($lines as $idx => $line) {
            if ($start === null) {
                if (self::matchBeginMarker($line) === $blockId) {
                    $start = $idx;
                }
            } else {
                if (self::matchEndMarker($line) === $blockId) {
                    return [$start, $idx];
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $vars
     * @return list<string>
     */
    private function renderBlockLines(string $blockId, array $vars): array
    {
        $out = ['# pb-migrate:begin ' . $blockId];
        foreach ($vars as $key => $value) {
            $out[] = $key . '=' . self::quoteValueIfNeeded($value);
        }
        $out[] = '# pb-migrate:end ' . $blockId;
        return $out;
    }

    /**
     * @return list<string>
     */
    private function readLines(): array
    {
        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new ConfigException(sprintf('Failed to read .env: %s', $this->path));
        }
        // file() with FILE_IGNORE_NEW_LINES strips \n but preserves blank lines
        $lines = preg_split('/\R/', rtrim($raw, "\n")) ?: [];
        return array_values($lines);
    }

    /**
     * @param list<string> $lines
     */
    private function writeLines(array $lines): void
    {
        $body = implode("\n", $lines);
        if ($body !== '') {
            $body .= "\n";
        }
        if (file_put_contents($this->path, $body) === false) {
            throw new ConfigException(sprintf('Failed to write .env: %s', $this->path));
        }
    }

    /**
     * Recognises `# pb-migrate:begin <id>` on a line. Allows leading
     * whitespace and an optional space between `#` and `pb-migrate:`.
     */
    private static function matchBeginMarker(string $line): ?string
    {
        if (preg_match('/^\s*#\s*pb-migrate:begin\s+(\S+)\s*$/', $line, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private static function matchEndMarker(string $line): ?string
    {
        if (preg_match('/^\s*#\s*pb-migrate:end\s+(\S+)\s*$/', $line, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Parse a `KEY=value` line. Returns null for blanks, comments, or
     * lines that don't look like assignments.
     *
     * @return array{0: string, 1: string}|null [key, value]
     */
    private static function parseLine(string $line): ?array
    {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $trimmed, $m) !== 1) {
            return null;
        }
        $key = $m[1];
        $value = self::unquoteValue($m[2]);
        return [$key, $value];
    }

    private static function quoteValueIfNeeded(string $value): string
    {
        // Empty value → bare `KEY=` is fine.
        // Otherwise, double-quote whenever the value contains whitespace,
        // a # (which Dotenv/shells treat as a comment marker), or quote
        // characters that would otherwise need their own escape handling.
        if ($value === '' || preg_match('/[\s#"\']/', $value) !== 1) {
            return $value;
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private static function unquoteValue(string $raw): string
    {
        $v = trim($raw);
        if ($v === '') {
            return '';
        }
        $first = $v[0];
        if (($first === '"' || $first === "'") && substr($v, -1) === $first && strlen($v) >= 2) {
            $inner = substr($v, 1, -1);
            if ($first === '"') {
                $inner = str_replace(['\\\\', '\\"', '\\n', '\\r', '\\t'], ['\\', '"', "\n", "\r", "\t"], $inner);
            }
            return $inner;
        }
        return $v;
    }
}
