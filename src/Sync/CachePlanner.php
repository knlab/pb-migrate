<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use KnLab\PbMigrate\Config\BotConfig;
use Spontena\PbPhp\FileKind;

/**
 * Compute the same kind of FileChangeSet as BotSync::plan(), but using the
 * local cache as the reference state instead of the live remote bot. Used by
 * `report --since=cache` to produce an inspection report of "what has changed
 * locally since the last successful push/pull" without making any API calls.
 *
 * Detection rules (mirroring StatusCommand's per-file logic, but emitting
 * FileChange objects so the existing report renderer can consume them):
 *   - local file with no cache entry           → ADD
 *   - local file whose hash differs from cache → UPDATE
 *   - cache entry with no matching local file  → DELETE
 *
 * The DELETE bucket is what `push --prune` would remove on the next push;
 * surfacing it here lets handoff documents call out files that were locally
 * deleted but not yet propagated.
 */
final class CachePlanner
{
    public function __construct(
        private readonly FileScanner $scanner,
        private readonly CacheStore $cache,
    ) {
    }

    /**
     * @return array{0: FileChangeSet, 1: list<LocalFile>}
     */
    public function plan(BotConfig $bot): array
    {
        $local = $this->scanner->scan($bot);
        $cacheEntries = $this->cache->entriesFor($bot->name);

        $changes = [];
        $seenKeys = [];

        foreach ($local as $f) {
            $key = $f->kind->value . '/' . ($f->kind->hasFilenameInPath() ? $f->name : '');
            $seenKeys[$key] = true;

            $cached = $cacheEntries[$key] ?? null;
            if ($cached === null) {
                $changes[] = new FileChange(FileChange::ADD, $f->kind, $f->name, $f->path);
            } elseif ($cached !== $f->hash) {
                $changes[] = new FileChange(FileChange::UPDATE, $f->kind, $f->name, $f->path);
            }
        }

        foreach ($cacheEntries as $key => $hash) {
            if (isset($seenKeys[$key])) {
                continue;
            }
            [$kindValue, $name] = self::parseKey($key);
            $kind = FileKind::tryFrom($kindValue);
            if ($kind === null) {
                continue;
            }
            $changes[] = new FileChange(FileChange::DELETE, $kind, $name);
        }

        return [new FileChangeSet($changes), $local];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function parseKey(string $key): array
    {
        $parts = explode('/', $key, 2);
        return [$parts[0], $parts[1] ?? ''];
    }
}
