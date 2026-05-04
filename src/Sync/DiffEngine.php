<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use Spontena\PbPhp\Exception\ApiException;
use Spontena\PbPhp\FileKind;
use Spontena\PbPhp\PBClient;

final class DiffEngine
{
    /**
     * Files Pandorabots itself maintains and refuses to relinquish (the bot
     * lifecycle creates them on `bot:create`; `DELETE` returns HTTP 412).
     * Filtering at planning time keeps them out of `diff` / `push` output —
     * showing them every push as a phantom DEL is just noise.
     */
    private const SYSTEM_MANAGED = [
        'file/udc',
    ];

    /**
     * Compute the set of operations that would make remote match local.
     *
     * Update detection is content-hash (SHA-256) based. The cache lets us
     * avoid fetching the remote body when local hash matches the value
     * recorded at the previous successful push/pull. With $fullCheck = true
     * the cache is bypassed and every conflicting file is verified against a
     * fresh remote download.
     *
     * @param list<LocalFile> $localFiles
     */
    public function compute(
        PBClient $client,
        string $botname,
        array $localFiles,
        RemoteIndex $remote,
        ?CacheStore $cache = null,
        bool $fullCheck = false,
    ): FileChangeSet {
        $localKeys = [];
        $changes = [];

        foreach ($localFiles as $local) {
            $key = $local->kind->value . '/' . ($local->kind->hasFilenameInPath() ? $local->name : '');
            $localKeys[$key] = true;

            if (!$remote->has($local->kind, $local->name)) {
                $changes[] = new FileChange(FileChange::ADD, $local->kind, $local->name, $local->path);
                continue;
            }

            // Cache fast-path: if we know this exact local content was the one
            // we previously synced, treat it as unchanged without round-tripping.
            if (!$fullCheck && $cache !== null) {
                $cached = $cache->get($botname, $local->kind, $local->name);
                if ($cached !== null) {
                    if ($cached === $local->hash) {
                        continue;
                    }
                    $changes[] = new FileChange(FileChange::UPDATE, $local->kind, $local->name, $local->path);
                    continue;
                }
            }

            try {
                $remoteBody = $client->getBotFile(
                    kind: $local->kind,
                    botname: $botname,
                    name: $local->kind->hasFilenameInPath() ? $local->name : null,
                );
            } catch (ApiException) {
                // System-managed file we cannot read; cannot determine drift, skip.
                continue;
            }

            if (!self::bodiesEquivalent($local, $remoteBody)) {
                $changes[] = new FileChange(FileChange::UPDATE, $local->kind, $local->name, $local->path);
            }
        }

        foreach ($remote->all() as $rem) {
            if (self::isSystemManaged($rem->kind, $rem->name)) {
                continue;
            }
            $key = $rem->kind->value . '/' . ($rem->kind->hasFilenameInPath() ? $rem->name : '');
            if (!isset($localKeys[$key])) {
                $changes[] = new FileChange(FileChange::DELETE, $rem->kind, $rem->name);
            }
        }

        return new FileChangeSet($changes);
    }

    private static function isSystemManaged(FileKind $kind, string $name): bool
    {
        return in_array($kind->value . '/' . $name, self::SYSTEM_MANAGED, true);
    }

    /**
     * Pandorabots reformats JSON-shaped kinds (everything except `file`) on
     * the server: a single-line upload comes back pretty-printed AND with
     * entries reordered. A raw byte hash therefore reports phantom drift on
     * every push. Compare those kinds via canonicalised JSON (sorted by the
     * first element of each entry) so we test semantic equality, which is
     * what the user wants from "is the remote in sync with local?".
     */
    private static function bodiesEquivalent(LocalFile $local, string $remoteBody): bool
    {
        if (self::isJsonKind($local->kind)) {
            $localCanon = self::canonicalJson((string) file_get_contents($local->path));
            $remoteCanon = self::canonicalJson($remoteBody);
            if ($localCanon !== null && $remoteCanon !== null) {
                return $localCanon === $remoteCanon;
            }
        }
        return hash('sha256', $remoteBody) === $local->hash;
    }

    private static function isJsonKind(FileKind $kind): bool
    {
        return $kind !== FileKind::File;
    }

    private static function canonicalJson(string $body): ?string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        // Sort outer entries by their JSON-encoded representation so the
        // server's reordering does not register as drift. The shape is
        // consistently `[[...], [...], ...]` for set / map / substitution /
        // properties / pdefaults — sorting by encoded form is order-independent
        // without needing per-kind shape knowledge.
        $entries = array_values($decoded);
        usort($entries, static function ($a, $b): int {
            $sa = json_encode($a) ?: '';
            $sb = json_encode($b) ?: '';
            return strcmp($sa, $sb);
        });
        $encoded = json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? null : $encoded;
    }
}
