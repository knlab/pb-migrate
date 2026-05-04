<?php

declare(strict_types=1);

namespace KnLab\PbMigrate;

use GuzzleHttp\ClientInterface;
use KnLab\PbMigrate\Config\ProjectConfig;
use Spontena\PbPhp\PBClient;

final class PBClientFactory
{
    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    /**
     * Build a PBClient with project-level credentials (PB_APP_ID + PB_USER_KEY).
     * Throws if either is missing — callers that work without credentials
     * (the `add` / `config` first-run prompts) should not call this.
     *
     * Per-bot bot_key (for atalk) is intentionally NOT plumbed here. atalk
     * resolves its bot_key separately via $config->botKey($botname).
     */
    public function forConfig(ProjectConfig $config): PBClient
    {
        return new PBClient(
            host: $config->host(),
            appId: $config->appId(),
            userKey: $config->userKey(),
            botKey: null,
            http: $this->http,
        );
    }

    /**
     * Build a PBClient configured for atalk on a specific bot. The bot's
     * bot_key is resolved from PB_BOT_<UPPER-NAME>_KEY. Throws via ConfigException
     * if the bot has no bot_key configured.
     */
    public function forAtalk(ProjectConfig $config, string $botname): PBClient
    {
        $botKey = $config->botKey($botname);
        if ($botKey === null) {
            throw new \KnLab\PbMigrate\Exception\ConfigException(sprintf(
                'No bot_key configured for "%s". Run `pb-migrate config --bot %s` to set one.',
                $botname,
                $botname,
            ));
        }
        return new PBClient(
            host: $config->host(),
            appId: $config->appId(),
            userKey: $config->userKey(),
            botKey: $botKey,
            http: $this->http,
        );
    }
}
