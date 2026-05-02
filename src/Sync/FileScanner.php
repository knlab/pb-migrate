<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use Spontena\PbPhp\FileKind;

final class FileScanner
{
    /**
     * Scan the bot's directory for AIML / set / map / substitution / pdefaults
     * / properties files. The optional $overrides map allows substituting a
     * single file's body with the contents of another path for the duration
     * of the call (useful for `push --override greet=variants/greet-test.aiml`,
     * which lets the user temporarily swap a single file without renaming).
     *
     * Override semantics:
     * - key = the logical name (matches the LocalFile.name produced by scan)
     * - value = absolute or cwd-relative path to the substitute body
     * - the kind is taken from the substitute's extension (must be valid)
     * - if the override key matches an existing file, that file's path/hash
     *   is replaced; if not, the override is added as a brand-new entry
     *
     * @param array<string, string> $overrides name → substitute path
     * @return list<LocalFile>
     */
    public function scan(BotConfig $bot, array $overrides = []): array
    {
        $files = [];

        if (is_dir($bot->directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($bot->directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $entry) {
                if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                    continue;
                }

                $extension = strtolower($entry->getExtension());
                $kind = FileKind::fromExtension($extension);
                if ($kind === null) {
                    continue;
                }

                $name = $entry->getBasename('.' . $entry->getExtension());
                $path = $entry->getPathname();
                $files[] = new LocalFile(
                    path: $path,
                    name: $name,
                    kind: $kind,
                    hash: hash_file('sha256', $path) ?: '',
                );
            }
        }

        if ($overrides !== []) {
            $files = $this->applyOverrides($files, $overrides);
        }

        usort($files, static fn (LocalFile $a, LocalFile $b) => strcmp(self::sortKey($a), self::sortKey($b)));
        return $files;
    }

    /**
     * @param list<LocalFile> $files
     * @param array<string, string> $overrides
     * @return list<LocalFile>
     */
    private function applyOverrides(array $files, array $overrides): array
    {
        foreach ($overrides as $name => $overridePath) {
            if (!is_file($overridePath)) {
                throw new ConfigException(sprintf('--override target not found: %s', $overridePath));
            }

            $extension = strtolower(pathinfo($overridePath, PATHINFO_EXTENSION));
            $kind = FileKind::fromExtension($extension);
            if ($kind === null) {
                throw new ConfigException(sprintf('--override target has unsupported extension: %s', $overridePath));
            }

            $hash = hash_file('sha256', $overridePath) ?: '';
            $replaced = false;

            foreach ($files as $i => $existing) {
                if ($existing->name === $name && $existing->kind === $kind) {
                    $files[$i] = new LocalFile($overridePath, $name, $kind, $hash);
                    $replaced = true;
                    break;
                }
            }

            if (!$replaced) {
                $files[] = new LocalFile($overridePath, $name, $kind, $hash);
            }
        }

        return $files;
    }

    private static function sortKey(LocalFile $f): string
    {
        return $f->kind->value . '/' . $f->name;
    }
}
