<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Config;

use KnLab\PbMigrate\Exception\ConfigException;

/**
 * Project configuration. The pb-migrate.json file holds **structure only**:
 * the bots map and per-bot settings (directory / propertiesUpload / alters).
 *
 * **Credentials live entirely in the environment** (and by convention in a
 * project-local `.env` managed by `pb-migrate config`). Look up via:
 *   - PB_APP_ID
 *   - PB_USER_KEY
 *   - PB_HOST                       (optional, defaults to api.pandorabots.com)
 *   - PB_BOT_<UPPER-BOTNAME>_KEY    (optional, per bot, only for atalk)
 *
 * One pb-migrate.json = one project = one app_id. Multi-app_id projects are
 * out of scope (covered by special partnership API tiers, not the public
 * Developer Portal pb-migrate targets).
 */
final class ProjectConfig
{
    public const DEFAULT_FILENAME = 'pb-migrate.json';
    public const DEFAULT_HOST = 'https://api.pandorabots.com';

    /**
     * @param array<string, BotConfig> $bots
     */
    public function __construct(
        private readonly array $bots,
        public readonly string $projectRoot,
        public readonly string $configPath,
    ) {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigException(sprintf(
                'Config file not found: %s. Run `pb-migrate add <directory>` to register your first bot.',
                $path,
            ));
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

        $configPath = realpath($path) ?: $path;
        $projectRoot = dirname($configPath);

        // Load .env so getenv() reads PB_APP_ID etc. set in the project-local file.
        EnvLoader::loadFrom($projectRoot);

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
            $directory = self::collapseDots($directory);

            $rawPropertiesUpload = $botRaw['propertiesUpload'] ?? BotConfig::PROPERTIES_UPLOAD_ADDITIVE;
            if (!is_string($rawPropertiesUpload) || !in_array($rawPropertiesUpload, [BotConfig::PROPERTIES_UPLOAD_ADDITIVE, BotConfig::PROPERTIES_UPLOAD_FULL], true)) {
                throw new ConfigException(sprintf(
                    'bot %s: propertiesUpload must be "additive" or "full", got %s',
                    $name,
                    is_scalar($rawPropertiesUpload) ? (string) $rawPropertiesUpload : gettype($rawPropertiesUpload),
                ));
            }

            $altersRaw = $botRaw['alters'] ?? [];
            if (!is_array($altersRaw)) {
                throw new ConfigException(sprintf('bot %s: alters must be an object map of name → path', $name));
            }
            $alters = [];
            foreach ($altersRaw as $alterName => $alterPath) {
                if (!is_string($alterName) || $alterName === '') {
                    throw new ConfigException(sprintf('bot %s: alter keys must be non-empty strings', $name));
                }
                if (!is_string($alterPath) || $alterPath === '') {
                    throw new ConfigException(sprintf('bot %s: alter "%s" must point at a non-empty string path', $name, $alterName));
                }
                $expandedPath = self::expand($alterPath);
                if (!self::isAbsolutePath($expandedPath)) {
                    $expandedPath = $projectRoot . DIRECTORY_SEPARATOR . $expandedPath;
                }
                $alters[$alterName] = $expandedPath;
            }

            $bots[$name] = new BotConfig($name, $directory, $rawPropertiesUpload, $alters);
        }

        return new self($bots, $projectRoot, $configPath);
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
            throw new ConfigException(sprintf('Unknown bot "%s" in config. Run `pb-migrate add` to register it first.', $name));
        }
        return $this->bots[$name];
    }

    public function hasBot(string $name): bool
    {
        return isset($this->bots[$name]);
    }

    /**
     * Pandorabots API host. Defaults to https://api.pandorabots.com when
     * PB_HOST is unset.
     */
    public function host(): string
    {
        $v = getenv('PB_HOST');
        return is_string($v) && $v !== '' ? $v : self::DEFAULT_HOST;
    }

    /**
     * Pandorabots application ID. Throws if PB_APP_ID is unset.
     */
    public function appId(): string
    {
        $v = getenv('PB_APP_ID');
        if (!is_string($v) || $v === '') {
            throw new ConfigException(
                'PB_APP_ID is not set. Run `pb-migrate config` to set it, or export it in your shell.',
            );
        }
        return $v;
    }

    /**
     * Pandorabots user key. Throws if PB_USER_KEY is unset.
     */
    public function userKey(): string
    {
        $v = getenv('PB_USER_KEY');
        if (!is_string($v) || $v === '') {
            throw new ConfigException(
                'PB_USER_KEY is not set. Run `pb-migrate config` to set it, or export it in your shell.',
            );
        }
        return $v;
    }

    /**
     * Per-bot bot_key for atalk (POST /talk?botkey=...). Returns null when
     * the bot has no associated bot_key. Looked up from the env var
     * `PB_BOT_<UPPER-BOTNAME>_KEY`.
     */
    public function botKey(string $botname): ?string
    {
        $envName = 'PB_BOT_' . strtoupper($botname) . '_KEY';
        $v = getenv($envName);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Whether PB_APP_ID and PB_USER_KEY are both set in the environment.
     * Used by `add` / `config` commands to detect first-time setup.
     */
    public function hasCredentials(): bool
    {
        $appId = getenv('PB_APP_ID');
        $userKey = getenv('PB_USER_KEY');
        return is_string($appId) && $appId !== '' && is_string($userKey) && $userKey !== '';
    }

    /**
     * Persist the alters map for a single bot back to pb-migrate.json,
     * preserving all other content. Re-reads the raw JSON from disk so
     * env-var substitution does NOT leak resolved values.
     *
     * @param array<string, string> $alters Map of name → path. Paths are stored
     *        as supplied; callers normalize relative-to-project-root if needed.
     */
    public static function saveAlters(string $configPath, string $botname, array $alters): void
    {
        $decoded = self::loadRaw($configPath);

        if (!isset($decoded['bots'][$botname]) || !is_array($decoded['bots'][$botname])) {
            throw new ConfigException(sprintf('Unknown bot "%s" in %s', $botname, $configPath));
        }

        if ($alters === []) {
            unset($decoded['bots'][$botname]['alters']);
        } else {
            $decoded['bots'][$botname]['alters'] = $alters;
        }

        self::saveRaw($configPath, $decoded);
    }

    /**
     * Add or update a bot entry in pb-migrate.json. Used by `add` command.
     * Preserves all other content and env-var literals.
     *
     * @param array<string, mixed> $botConfig Per-bot fields: directory, optional propertiesUpload / alters
     */
    public static function saveBot(string $configPath, string $botname, array $botConfig): void
    {
        $decoded = is_file($configPath) ? self::loadRaw($configPath) : ['bots' => []];

        if (!isset($decoded['bots']) || !is_array($decoded['bots'])) {
            $decoded['bots'] = [];
        }

        $decoded['bots'][$botname] = $botConfig;
        ksort($decoded['bots']);

        self::saveRaw($configPath, $decoded);
    }

    /**
     * Remove a bot entry from pb-migrate.json. Used by `remove` command.
     */
    public static function removeBot(string $configPath, string $botname): void
    {
        $decoded = self::loadRaw($configPath);

        if (!isset($decoded['bots']) || !is_array($decoded['bots'])) {
            return;
        }

        unset($decoded['bots'][$botname]);

        self::saveRaw($configPath, $decoded);
    }

    /**
     * Resolve a user-supplied alter path so it can be stored in pb-migrate.json
     * portably. Absolute paths stay absolute; relative paths resolve against
     * the project root. Paths inside the project root are stored relative.
     */
    public static function normalizeAlterPath(string $userInput, string $projectRoot): string
    {
        if (self::isAbsolutePath($userInput)) {
            $absolute = $userInput;
        } else {
            $absolute = $projectRoot . DIRECTORY_SEPARATOR . $userInput;
        }

        $real = realpath($absolute);
        if ($real === false) {
            throw new ConfigException(sprintf('Alter target does not exist: %s', $userInput));
        }

        $projectReal = realpath($projectRoot);
        if ($projectReal !== false && str_starts_with($real, $projectReal . DIRECTORY_SEPARATOR)) {
            return substr($real, strlen($projectReal) + 1);
        }
        return $real;
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
     * @return array<string, mixed>
     */
    private static function loadRaw(string $configPath): array
    {
        if (!is_file($configPath)) {
            throw new ConfigException(sprintf('Config file not found: %s', $configPath));
        }
        $raw = file_get_contents($configPath);
        if ($raw === false) {
            throw new ConfigException(sprintf('Failed to read config: %s', $configPath));
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException(sprintf('Invalid JSON in %s: %s', $configPath, $e->getMessage()), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new ConfigException(sprintf('Config root must be a JSON object: %s', $configPath));
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function saveRaw(string $configPath, array $decoded): void
    {
        $output = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (file_put_contents($configPath, $output . "\n") === false) {
            throw new ConfigException(sprintf('Failed to write config: %s', $configPath));
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    /**
     * Collapse `/./` segments produced when the JSON-stored relative form
     * (e.g. `./aiml/foo`) gets concatenated with the project root. Doesn't
     * touch the filesystem (the directory may not exist yet at load time).
     */
    private static function collapseDots(string $path): string
    {
        // Iteratively replace `/./` with `/` until no more occurrences remain.
        while (str_contains($path, '/./')) {
            $path = str_replace('/./', '/', $path);
        }
        // Trailing `/.` (e.g. `${root}/.`) → drop the dot, keep one trailing slash.
        if (str_ends_with($path, '/.')) {
            $path = substr($path, 0, -1);
        }
        return $path;
    }
}
