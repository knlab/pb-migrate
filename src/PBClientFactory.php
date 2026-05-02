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

    public function forConfig(ProjectConfig $config): PBClient
    {
        return new PBClient(
            host: $config->host,
            appId: $config->appId,
            userKey: $config->userKey,
            botKey: $config->botKey,
            http: $this->http,
        );
    }
}
