<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Config;

use KnLab\PbMigrate\Exception\ConfigException;

/**
 * Resolve bot selectors (a single name or a glob pattern like "app.*") into
 * the concrete list of BotConfig entries from a ProjectConfig.
 *
 * Supported syntax:
 * - exact name:    "mybot"
 * - glob patterns: "*", "prod.*", "*-greeter"
 *
 * The wildcard `*` matches one or more characters (any except a path separator
 * is not enforced — bot names are expected to be identifier-like alphanumeric
 * tokens, so a permissive `.+` translation is fine).
 */
final class BotMatcher
{
    /**
     * @return list<BotConfig>
     */
    public static function resolve(ProjectConfig $config, string $selector): array
    {
        if ($selector === '') {
            throw new ConfigException('Empty bot selector');
        }

        $bots = $config->bots();
        if ($bots === []) {
            return [];
        }

        if (!self::looksLikePattern($selector)) {
            if (!isset($bots[$selector])) {
                throw new ConfigException(sprintf('Unknown bot "%s" in config', $selector));
            }
            return [$bots[$selector]];
        }

        $regex = self::globToRegex($selector);
        $matched = [];
        foreach ($bots as $name => $bot) {
            if (preg_match($regex, $name) === 1) {
                $matched[] = $bot;
            }
        }

        if ($matched === []) {
            throw new ConfigException(sprintf('No bots matched selector "%s"', $selector));
        }

        return $matched;
    }

    /**
     * @return list<BotConfig>
     */
    public static function all(ProjectConfig $config): array
    {
        $bots = $config->bots();
        if ($bots === []) {
            throw new ConfigException('No bots defined in pb-migrate.json');
        }
        return array_values($bots);
    }

    public static function looksLikePattern(string $selector): bool
    {
        return str_contains($selector, '*');
    }

    private static function globToRegex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '/');
        $regex = str_replace('\\*', '.+', $quoted);
        return '/^' . $regex . '$/';
    }
}
