<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use KnLab\PbMigrate\Config\BotConfig;
use Spontena\PbPhp\FileKind;

final class FileScanner
{
    /**
     * @return list<LocalFile>
     */
    public function scan(BotConfig $bot): array
    {
        if (!is_dir($bot->directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($bot->directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
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

        usort($files, static fn (LocalFile $a, LocalFile $b) => strcmp(self::sortKey($a), self::sortKey($b)));
        return $files;
    }

    private static function sortKey(LocalFile $f): string
    {
        return $f->kind->value . '/' . $f->name;
    }
}
