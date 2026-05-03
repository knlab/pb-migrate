<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Repl;

/**
 * Parse a runbook file into a list of executable command lines.
 *
 * Format:
 * - one command per non-empty line
 * - lines starting with `#` (after optional leading whitespace) are comments and skipped
 * - blank lines are skipped
 * - trailing whitespace is trimmed
 */
final class RunbookParser
{
    /**
     * @return list<string> command lines (already trimmed, comments/blanks removed)
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Runbook file not found: %s', $path));
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Cannot read runbook: %s', $path));
        }
        return self::parseString($raw);
    }

    /**
     * @return list<string>
     */
    public static function parseString(string $contents): array
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $out[] = $trimmed;
        }
        return $out;
    }
}
