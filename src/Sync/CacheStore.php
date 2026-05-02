<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use Spontena\PbPhp\FileKind;

/**
 * Local push/pull cache. Stores the SHA-256 of each file last seen for a
 * given (botname, kind, name). Allows DiffEngine to skip remote-body fetches
 * when local hash already matches the cached value (i.e. nothing has changed
 * locally since the last successful push/pull).
 *
 * On-disk format: pretty-printed JSON, gitignored, lives next to pb-migrate.json.
 *
 * {
 *   "version": 1,
 *   "bots": {
 *     "mybot": {
 *       "files": {
 *         "file/greet": { "sha256": "..." },
 *         "set/colors": { "sha256": "..." }
 *       }
 *     }
 *   }
 * }
 */
final class CacheStore
{
    public const FILENAME = '.pb-migrate-cache.json';
    private const VERSION = 1;

    /** @var array<string, array<string, string>> botname → "kind/name" → hash */
    private array $bots = [];

    private bool $dirty = false;

    public function __construct(public readonly string $path)
    {
    }

    public static function forProjectRoot(string $projectRoot): self
    {
        $store = new self($projectRoot . DIRECTORY_SEPARATOR . self::FILENAME);
        $store->load();
        return $store;
    }

    public function load(): void
    {
        if (!is_file($this->path)) {
            return;
        }

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            return;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (!is_array($decoded) || !isset($decoded['bots']) || !is_array($decoded['bots'])) {
            return;
        }

        foreach ($decoded['bots'] as $botname => $bot) {
            if (!is_string($botname) || !is_array($bot) || !isset($bot['files']) || !is_array($bot['files'])) {
                continue;
            }
            foreach ($bot['files'] as $key => $entry) {
                if (!is_string($key) || !is_array($entry) || !isset($entry['sha256']) || !is_string($entry['sha256'])) {
                    continue;
                }
                $this->bots[$botname][$key] = $entry['sha256'];
            }
        }
    }

    public function get(string $botname, FileKind $kind, string $name): ?string
    {
        $key = self::key($kind, $name);
        return $this->bots[$botname][$key] ?? null;
    }

    public function set(string $botname, FileKind $kind, string $name, string $sha256): void
    {
        $key = self::key($kind, $name);
        if (($this->bots[$botname][$key] ?? null) === $sha256) {
            return;
        }
        $this->bots[$botname][$key] = $sha256;
        $this->dirty = true;
    }

    public function forget(string $botname, FileKind $kind, string $name): void
    {
        $key = self::key($kind, $name);
        if (!isset($this->bots[$botname][$key])) {
            return;
        }
        unset($this->bots[$botname][$key]);
        if ($this->bots[$botname] === []) {
            unset($this->bots[$botname]);
        }
        $this->dirty = true;
    }

    public function clear(string $botname): void
    {
        if (!isset($this->bots[$botname])) {
            return;
        }
        unset($this->bots[$botname]);
        $this->dirty = true;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $payload = ['version' => self::VERSION, 'bots' => []];
        foreach ($this->bots as $botname => $entries) {
            $files = [];
            foreach ($entries as $key => $hash) {
                $files[$key] = ['sha256' => $hash];
            }
            ksort($files);
            $payload['bots'][$botname] = ['files' => $files];
        }
        ksort($payload['bots']);

        file_put_contents(
            $this->path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        $this->dirty = false;
    }

    private static function key(FileKind $kind, string $name): string
    {
        return $kind->value . '/' . ($kind->hasFilenameInPath() ? $name : '');
    }
}
