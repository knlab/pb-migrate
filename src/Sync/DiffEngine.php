<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use Spontena\PbPhp\Exception\ApiException;
use Spontena\PbPhp\FileKind;
use Spontena\PbPhp\PBClient;

final class DiffEngine
{
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

            if (hash('sha256', $remoteBody) !== $local->hash) {
                $changes[] = new FileChange(FileChange::UPDATE, $local->kind, $local->name, $local->path);
            }
        }

        foreach ($remote->all() as $rem) {
            $key = $rem->kind->value . '/' . ($rem->kind->hasFilenameInPath() ? $rem->name : '');
            if (!isset($localKeys[$key])) {
                $changes[] = new FileChange(FileChange::DELETE, $rem->kind, $rem->name);
            }
        }

        return new FileChangeSet($changes);
    }

}
