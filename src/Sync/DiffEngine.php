<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Spontena\PbPhp\Exception\ApiException;
use Spontena\PbPhp\FileKind;
use Spontena\PbPhp\PBClient;

final class DiffEngine
{
    /**
     * Compute the set of operations that would make remote match local.
     * Update detection is content-hash based: each conflicting (kind,name) pair
     * fetches the remote body once via PBClient::getBotFile() to compare sha1.
     *
     * @param list<LocalFile> $localFiles
     */
    public function compute(
        PBClient $client,
        string $botname,
        array $localFiles,
        RemoteIndex $remote,
    ): FileChangeSet {
        $localKeys = [];
        $changes = [];

        foreach ($localFiles as $local) {
            $key = $local->kind->value . '/' . $local->name;
            $localKeys[$key] = true;

            if (!$remote->has($local->kind, $local->name)) {
                $changes[] = new FileChange(FileChange::ADD, $local->kind, $local->name, $local->path);
                continue;
            }

            try {
                $remoteBody = $client->getBotFile(
                    kind: $local->kind,
                    botname: $botname,
                    name: $local->kind->hasFilenameInPath() ? $local->name : null,
                );
            } catch (ApiException) {
                // System-managed file we cannot read; cannot determine drift,
                // so skip update detection. The user can force-upload by
                // editing locally or restoring from a backup.
                continue;
            }

            if (sha1($remoteBody) !== $local->sha1) {
                $changes[] = new FileChange(FileChange::UPDATE, $local->kind, $local->name, $local->path);
            }
        }

        foreach ($remote->all() as $rem) {
            $key = $rem->kind->value . '/' . $rem->name;
            if (!isset($localKeys[$key])) {
                $changes[] = new FileChange(FileChange::DELETE, $rem->kind, $rem->name);
            }
        }

        return new FileChangeSet($changes);
    }

    public function unified(string $local, string $remote, string $label): string
    {
        $builder = new UnifiedDiffOutputBuilder("--- remote/{$label}\n+++ local/{$label}\n", false);
        $differ = new Differ($builder);
        return $differ->diff($remote, $local);
    }
}
