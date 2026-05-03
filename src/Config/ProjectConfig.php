<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Config;

use KnLab\PbMigrate\Exception\ConfigException;

final class ProjectConfig
{
    public const DEFAULT_FILENAME = 'pb-migrate.json';

    /**
     * @param array<string, BotConfig> $bots
     */
    public function __construct(
        public readonly string $host,
        public readonly string $appId,
        public readonly string $userKey,
        public readonly ?string $botKey,
        private readonly array $bots,
        public readonly string $projectRoot,
    ) {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigException(sprintf('Config file not found: %s', $path));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ConfigException(sprintf('Failed to read config: %s', $path));
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException(sprintf('Invalid JSON in %s: %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new ConfigException(sprintf('Config root must be a JSON object: %s', $path));
        }

        $projectRoot = dirname(realpath($path) ?: $path);

        $host = self::expandRequired($decoded, 'host', $path);
        $appId = self::expandRequired($decoded, 'appId', $path);
        $userKey = self::expandRequired($decoded, 'userKey', $path);
        $botKey = self::expandOptional($decoded, 'botKey');

        $botsRaw = $decoded['bots'] ?? [];
        if (!is_array($botsRaw)) {
            throw new ConfigException('bots must be an object keyed by bot name');
        }

        $bots = [];
        foreach ($botsRaw as $name => $botRaw) {
            if (!is_string($name) || $name === '') {
                throw new ConfigException('bot keys must be non-empty strings');
            }
            if (!is_array($botRaw)) {
                throw new ConfigException(sprintf('bot %s must be an object', $name));
            }

            $directory = self::expand((string) ($botRaw['directory'] ?? ''));
            if ($directory === '') {
                throw new ConfigException(sprintf('bot %s requires a "directory"', $name));
            }
            if (!self::isAbsolutePath($directory)) {
                $directory = $projectRoot . DIRECTORY_SEPARATOR . $directory;
            }

            $files = self::expand((string) ($botRaw['files'] ?? '*'));

            $rawPropertiesUpload = $botRaw['propertiesUpload'] ?? BotConfig::PROPERTIES_UPLOAD_ADDITIVE;
            if (!is_string($rawPropertiesUpload) || !in_array($rawPropertiesUpload, [BotConfig::PROPERTIES_UPLOAD_ADDITIVE, BotConfig::PROPERTIES_UPLOAD_FULL], true)) {
                throw new ConfigException(sprintf(
                    'bot %s: propertiesUpload must be "additive" or "full", got %s',
                    $name,
                    is_scalar($rawPropertiesUpload) ? (string) $rawPropertiesUpload : gettype($rawPropertiesUpload),
                ));
            }

            $bots[$name] = new BotConfig($name, $directory, $files !== '' ? $files : '*', $rawPropertiesUpload);
        }

        return new self($host, $appId, $userKey, $botKey, $bots, $projectRoot);
    }

    /**
     * @return array<string, BotConfig>
     */
    public function bots(): array
    {
        return $this->bots;
    }

    public function bot(string $name): BotConfig
    {
        if (!isset($this->bots[$name])) {
            throw new ConfigException(sprintf('Unknown bot "%s" in config', $name));
        }
        return $this->bots[$name];
    }

    /**
     * Expand `${VAR}` and `${VAR:-default}` from environment variables.
     */
    public static function expand(string $value): string
    {
        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}/',
            static function (array $m): string {
                $env = getenv($m[1]);
                if ($env !== false && $env !== '') {
                    return $env;
                }
                return $m[2] ?? '';
            },
            $value,
        ) ?? $value;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function expandRequired(array $decoded, string $key, string $path): string
    {
        $raw = $decoded[$key] ?? null;
        if (!is_string($raw)) {
            throw new ConfigException(sprintf('"%s" must be a string in %s', $key, $path));
        }
        $expanded = self::expand($raw);
        if ($expanded === '') {
            throw new ConfigException(sprintf('"%s" is empty (after env expansion) in %s', $key, $path));
        }
        return $expanded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function expandOptional(array $decoded, string $key): ?string
    {
        $raw = $decoded[$key] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $expanded = self::expand($raw);
        return $expanded !== '' ? $expanded : null;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }
}
