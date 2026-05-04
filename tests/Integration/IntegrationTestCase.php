<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Integration;

use KnLab\PbMigrate\Application;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\Exception\ApiException;
use Spontena\PbPhp\PBClient;

abstract class IntegrationTestCase extends TestCase
{
    protected Application $app;
    protected PBClient $client;
    protected string $tmpDir;
    protected string $configPath;

    /** @var list<string> */
    private array $createdBots = [];

    protected function setUp(): void
    {
        $appId = getenv('PB_APP_ID');
        $userKey = getenv('PB_USER_KEY');

        if (!is_string($appId) || $appId === '' || !is_string($userKey) || $userKey === '') {
            $this->markTestSkipped('Set PB_APP_ID and PB_USER_KEY to run integration tests.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/pbm-it-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);

        $this->configPath = $this->tmpDir . '/pb-migrate.json';
        file_put_contents($this->configPath, (string) json_encode([
            'bots' => new \stdClass(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $this->app = new Application();
        $this->client = new PBClient(
            host: getenv('PB_HOST') ?: 'https://api.pandorabots.com',
            appId: $appId,
            userKey: $userKey,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->createdBots as $name) {
            try {
                $this->client->delete($name);
            } catch (ApiException) {
                // best-effort cleanup
            }
        }
        $this->createdBots = [];

        $this->rrmdir($this->tmpDir);
    }

    protected function reserveBotname(string $hint = 'test'): string
    {
        $clean = substr(preg_replace('/[^a-z0-9]/', '', strtolower($hint)) ?: 'test', 0, 8);
        $name = sprintf('pbmigrate%s%s', $clean, bin2hex(random_bytes(4)));
        $this->createdBots[] = $name;
        return $name;
    }

    protected function writeConfigForBot(string $botname, string $localDir): void
    {
        file_put_contents($this->configPath, (string) json_encode([
            'bots' => [$botname => ['directory' => $localDir]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
