<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Config;

use KnLab\PbMigrate\Config\ProjectConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class ProjectConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-cfg-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/pb-migrate.json');
        @rmdir($this->tmpDir);
        foreach (['PB_APP_ID', 'PB_USER_KEY', 'PB_BOT_KEY', 'PB_HOST'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function writeConfig(string $json): string
    {
        $path = $this->tmpDir . '/pb-migrate.json';
        file_put_contents($path, $json);
        return $path;
    }

    public function testLoadExpandsEnvVariables(): void
    {
        putenv('PB_APP_ID=app-123');
        putenv('PB_USER_KEY=key-456');

        $path = $this->writeConfig(json_encode([
            'host' => '${PB_HOST:-https://api.pandorabots.com}',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => ['mybot' => ['directory' => './aiml/mybot', 'files' => '*']],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);

        $this->assertSame('https://api.pandorabots.com', $cfg->host);
        $this->assertSame('app-123', $cfg->appId);
        $this->assertSame('key-456', $cfg->userKey);
        $this->assertNull($cfg->botKey);
        $this->assertArrayHasKey('mybot', $cfg->bots());
        // realpath() may add /private prefix on macOS; compare via realpath of resolved root.
        $expected = (realpath($this->tmpDir) ?: $this->tmpDir) . '/./aiml/mybot';
        $this->assertSame($expected, $cfg->bot('mybot')->directory);
    }

    public function testLoadHonoursDefaultValueSyntax(): void
    {
        putenv('PB_APP_ID=a');
        putenv('PB_USER_KEY=b');

        $path = $this->writeConfig(json_encode([
            'host' => '${PB_HOST:-https://default.host}',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => [],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);
        $this->assertSame('https://default.host', $cfg->host);
    }

    public function testLoadFailsOnMissingRequiredField(): void
    {
        $path = $this->writeConfig(json_encode([
            'host' => 'https://x',
            'appId' => '',
            'userKey' => 'k',
            'bots' => [],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(ConfigException::class);
        ProjectConfig::load($path);
    }

    public function testLoadFailsOnInvalidJson(): void
    {
        $path = $this->writeConfig('not json');
        $this->expectException(ConfigException::class);
        ProjectConfig::load($path);
    }

    public function testBotLookupThrowsWhenUnknown(): void
    {
        putenv('PB_APP_ID=a');
        putenv('PB_USER_KEY=b');

        $path = $this->writeConfig(json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => ['known' => ['directory' => './aiml/known']],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);
        $this->expectException(ConfigException::class);
        $cfg->bot('unknown');
    }

    public function testExpandLeavesUnsetVariablesEmpty(): void
    {
        putenv('NEVER_SET');
        $this->assertSame('', ProjectConfig::expand('${NEVER_SET}'));
        $this->assertSame('fallback', ProjectConfig::expand('${NEVER_SET:-fallback}'));
    }
}
