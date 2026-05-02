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
    ) {
    }

    public function plan(BotConfig $bot): FileChangeSet
    {
        $local = $this->scanner->scan($bot);
        $remote = RemoteIndex::fromResponse($this->client->getBotFiles($bot->name));
        return $this->diff->compute($this->client, $bot->name, $local, $remote);
    }

    public function applyPush(BotConfig $bot, FileChangeSet $changes, SymfonyStyle $io, bool $prune = false): void
    {
        if ($changes->isEmpty()) {
            $io->writeln('  <comment>nothing to push</comment>');
            return;
        }

        foreach ($changes->byAction(FileChange::DELETE) as $change) {
            if (!$prune) {
                $io->writeln(sprintf('  <fg=red>-</> %s/%s <comment>(skipped — pass --prune to delete)</comment>', $change->kind->value, $change->name));
                continue;
            }
            $io->writeln(sprintf('  <fg=red>-</> %s/%s', $change->kind->value, $change->name));
            $this->client->deleteBotFile(
                fname: $change->name,
                fkind: $change->kind,
                botname: $bot->name,
            );
        }

        foreach ([FileChange::ADD, FileChange::UPDATE] as $action) {
            foreach ($changes->byAction($action) as $change) {
                $marker = $action === FileChange::ADD ? '+' : '~';
                $color = $action === FileChange::ADD ? 'green' : 'yellow';
                $io->writeln(sprintf('  <fg=%s>%s</> %s/%s', $color, $marker, $change->kind->value, $change->name));
                if ($change->localPath === null) {
                    continue;
                }
                $this->client->upload($change->localPath, $bot->name);
            }
        }
    }

    public function compile(BotConfig $bot, SymfonyStyle $io): void
    {
        $io->writeln('  <comment>compile</comment>');
        $this->client->compile($bot->name);
    }

    public function pull(BotConfig $bot, SymfonyStyle $io): int
    {
        $remote = RemoteIndex::fromResponse($this->client->getBotFiles($bot->name));
        $count = 0;

        if (!is_dir($bot->directory) && !mkdir($bot->directory, 0o755, true) && !is_dir($bot->directory)) {
            throw new PullException(sprintf('Could not create local directory: %s', $bot->directory));
        }

        foreach ($remote->all() as $remoteFile) {
            try {
                $body = $this->client->getBotFile(
                    kind: $remoteFile->kind,
                    botname: $bot->name,
                    name: $remoteFile->kind->hasFilenameInPath() ? $remoteFile->name : null,
                );
            } catch (ApiException $e) {
                // System-managed files (e.g. the bot's default `udc`) appear in
                // getBotFiles() but cannot be downloaded — Pandorabots returns
                // HTTP 412 "precondition failed". Skip them with a warning.
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
            $io->writeln(sprintf('  <fg=cyan>↓</> %s/%s → %s', $remoteFile->kind->value, $remoteFile->name, $relative));
            $count++;
        }

        return $count;
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
