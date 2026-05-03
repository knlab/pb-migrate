<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Exception\PullException;
use Spontena\PbPhp\Exception\ApiException;
use Spontena\PbPhp\FileKind;
use Spontena\PbPhp\PBClient;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BotSync
{
    public function __construct(
        private readonly PBClient $client,
        private readonly FileScanner $scanner,
        private readonly DiffEngine $diff,
        private readonly ?CacheStore $cache = null,
    ) {
    }

    /**
     * @param array<string, string> $overrides name → substitute file path
     * @return array{0: FileChangeSet, 1: list<LocalFile>}
     */
    public function plan(BotConfig $bot, bool $fullCheck = false, array $overrides = []): array
    {
        $local = $this->scanner->scan($bot, $overrides);
        $remote = RemoteIndex::fromResponse($this->client->getBotFiles($bot->name));
        $changes = $this->diff->compute(
            client: $this->client,
            botname: $bot->name,
            localFiles: $local,
            remote: $remote,
            cache: $this->cache,
            fullCheck: $fullCheck,
        );
        return [$changes, $local];
    }

    /**
     * @param list<LocalFile> $localFiles
     */
    public function applyPush(BotConfig $bot, FileChangeSet $changes, array $localFiles, SymfonyStyle $io, bool $prune = false): void
    {
        if ($changes->isEmpty()) {
            $io->writeln('  <comment>nothing to push</comment>');
            return;
        }

        $localByKey = [];
        foreach ($localFiles as $f) {
            $localByKey[$f->kind->value . '/' . ($f->kind->hasFilenameInPath() ? $f->name : '')] = $f;
        }

        foreach ($changes->byAction(FileChange::DELETE) as $change) {
            if (!$prune) {
                $io->writeln(sprintf('  <fg=red>-</> %s/%s <comment>(skipped — pass --prune to delete)</comment>', $change->kind->value, $change->name));
                continue;
            }
            $io->writeln(sprintf('  <fg=red>-</> %s/%s', $change->kind->value, $change->name));
            // Workaround for spontena/pb-php v2.1.0: deleteBotFile asserts fname
            // is non-empty for kinds without a filename in the URL too. Pass
            // the kind value as a placeholder for those kinds.
            $fnameForApi = $change->kind->hasFilenameInPath() ? $change->name : $change->kind->value;
            $this->client->deleteBotFile(
                fname: $fnameForApi,
                fkind: $change->kind,
                botname: $bot->name,
            );
            $this->cache?->forget($bot->name, $change->kind, $change->name);
        }

        foreach ([FileChange::ADD, FileChange::UPDATE] as $action) {
            foreach ($changes->byAction($action) as $change) {
                $marker = $action === FileChange::ADD ? '+' : '~';
                $color = $action === FileChange::ADD ? 'green' : 'yellow';
                $io->writeln(sprintf('  <fg=%s>%s</> %s/%s', $color, $marker, $change->kind->value, $change->name));
                if ($change->localPath === null) {
                    continue;
                }

                if ($change->kind === FileKind::Properties && $bot->propertiesUpload === BotConfig::PROPERTIES_UPLOAD_FULL) {
                    $io->writeln('    <comment>(propertiesUpload=full: clearing remote properties first)</comment>');
                    try {
                        $this->client->deleteBotFile(
                            fname: FileKind::Properties->value, // pb-php v2.1.0 placeholder; URL ignores it
                            fkind: FileKind::Properties,
                            botname: $bot->name,
                        );
                    } catch (ApiException $e) {
                        // The server might 404 if there are no properties yet — ignore that case.
                        if ($e->getStatusCode() !== 404) {
                            throw $e;
                        }
                    }
                }

                $this->client->upload($change->localPath, $bot->name);

                $key = $change->kind->value . '/' . ($change->kind->hasFilenameInPath() ? $change->name : '');
                $localFile = $localByKey[$key] ?? null;
                if ($localFile !== null) {
                    $this->cache?->set($bot->name, $change->kind, $change->name, $localFile->hash);
                }
            }
        }

        $this->cache?->save();
    }

    public function compile(BotConfig $bot, SymfonyStyle $io): void
    {
        $io->writeln('  <comment>compile</comment>');
        $this->client->compile($bot->name);
    }

    /**
     * @param list<string> $only patterns ("name" or "kind/name") to restrict pull to
     */
    public function pull(BotConfig $bot, SymfonyStyle $io, array $only = []): int
    {
        $remote = RemoteIndex::fromResponse($this->client->getBotFiles($bot->name));
        $count = 0;

        if (!is_dir($bot->directory) && !mkdir($bot->directory, 0o755, true) && !is_dir($bot->directory)) {
            throw new PullException(sprintf('Could not create local directory: %s', $bot->directory));
        }

        foreach ($remote->all() as $remoteFile) {
            if ($only !== [] && !$this->matchesAny($remoteFile, $only)) {
                continue;
            }

            try {
                $body = $this->client->getBotFile(
                    kind: $remoteFile->kind,
                    botname: $bot->name,
                    name: $remoteFile->kind->hasFilenameInPath() ? $remoteFile->name : null,
                );
            } catch (ApiException $e) {
                $io->writeln(sprintf(
                    '  <comment>skip %s/%s — server returned HTTP %d (likely a system-managed file)</comment>',
                    $remoteFile->kind->value,
                    $remoteFile->name,
                    $e->getStatusCode(),
                ));
                continue;
            }

            $relative = $this->localFilename($remoteFile->kind, $remoteFile->name);
            $target = $bot->directory . DIRECTORY_SEPARATOR . $relative;

            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new PullException(sprintf('Could not create directory: %s', $dir));
            }

            file_put_contents($target, $body);
            $this->cache?->set($bot->name, $remoteFile->kind, $remoteFile->name, hash('sha256', $body));

            $io->writeln(sprintf('  <fg=cyan>↓</> %s/%s → %s', $remoteFile->kind->value, $remoteFile->name, $relative));
            $count++;
        }

        $this->cache?->save();
        return $count;
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAny(RemoteFile $remoteFile, array $patterns): bool
    {
        $key = $remoteFile->kind->value . '/' . $remoteFile->name;
        foreach ($patterns as $p) {
            if ($p === $remoteFile->name || $p === $key) {
                return true;
            }
        }
        return false;
    }

    private function localFilename(FileKind $kind, string $name): string
    {
        if (!$kind->hasFilenameInPath()) {
            return $kind->value;
        }

        return match ($kind) {
            FileKind::File => $name . '.aiml',
            FileKind::Set => $name . '.set',
            FileKind::Map => $name . '.map',
            FileKind::Substitution => $name . '.substitution',
            default => $name,
        };
    }
}
