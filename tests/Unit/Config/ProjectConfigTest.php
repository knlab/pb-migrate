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
        @unlink($this->tmpDir . '/.env');
        @rmdir($this->tmpDir);
        foreach (['PB_APP_ID', 'PB_USER_KEY', 'PB_HOST', 'PB_BOT_GREETER_KEY'] as $key) {
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

    public function testLoadParsesBotsAndExposesProjectRoot(): void
    {
        $path = $this->writeConfig(json_encode([
            'bots' => ['mybot' => ['directory' => './aiml/mybot']],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);

        $this->assertArrayHasKey('mybot', $cfg->bots());
        $expected = (realpath($this->tmpDir) ?: $this->tmpDir) . '/./aiml/mybot';
        $this->assertSame($expected, $cfg->bot('mybot')->directory);
        $this->assertSame(realpath($this->tmpDir), $cfg->projectRoot);
    }

    public function testCredentialsResolveFromEnvironment(): void
    {
        putenv('PB_APP_ID=app-123');
        putenv('PB_USER_KEY=key-456');

        $path = $this->writeConfig(json_encode([
            'bots' => ['mybot' => ['directory' => './aiml/mybot']],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);

        $this->assertSame('app-123', $cfg->appId());
        $this->assertSame('key-456', $cfg->userKey());
        $this->assertSame('https://api.pandorabots.com', $cfg->host(), 'PB_HOST default applies when unset');
        $this->assertTrue($cfg->hasCredentials());
    }

    public function testHostDefaultIsAppliedWhenPbHostUnset(): void
    {
        putenv('PB_APP_ID=a');
        putenv('PB_USER_KEY=b');
        $path = $this->writeConfig(json_encode([
            'bots' => [],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);
        $this->assertSame(ProjectConfig::DEFAULT_HOST, $cfg->host());
    }

    public function testHostHonoursPbHostEnvVar(): void
    {
        putenv('PB_APP_ID=a');
        putenv('PB_USER_KEY=b');
        putenv('PB_HOST=https://staging.example');
        $path = $this->writeConfig(json_encode([
            'bots' => [],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);
        $this->assertSame('https://staging.example', $cfg->host());
    }

    public function testAppIdThrowsWhenNotSet(): void
    {
        $path = $this->writeConfig(json_encode([
            'bots' => [],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);
        $this->assertFalse($cfg->hasCredentials());
        $this->expectException(ConfigException::class);
        $cfg->appId();
    }

    public function testBotKeyResolvedPerBotFromEnvironment(): void
    {
        putenv('PB_BOT_GREETER_KEY=secret-greet-key');
        $path = $this->writeConfig(json_encode([
            'bots' => ['greeter' => ['directory' => './aiml/greeter']],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);
        $this->assertSame('secret-greet-key', $cfg->botKey('greeter'));
        $this->assertNull($cfg->botKey('otherbot'), 'unset bot_key returns null');
    }

    public function testLoadFailsOnInvalidJson(): void
    {
        $path = $this->writeConfig('not json');
        $this->expectException(ConfigException::class);
        ProjectConfig::load($path);
    }

    public function testBotLookupThrowsWhenUnknown(): void
    {
        $path = $this->writeConfig(json_encode([
            'bots' => ['known' => ['directory' => './aiml/known']],
        ], JSON_THROW_ON_ERROR));

        $cfg = ProjectConfig::load($path);
        $this->assertTrue($cfg->hasBot('known'));
        $this->assertFalse($cfg->hasBot('unknown'));
        $this->expectException(ConfigException::class);
        $cfg->bot('unknown');
    }

    public function testExpandLeavesUnsetVariablesEmpty(): void
    {
        putenv('NEVER_SET');
        $this->assertSame('', ProjectConfig::expand('${NEVER_SET}'));
        $this->assertSame('fallback', ProjectConfig::expand('${NEVER_SET:-fallback}'));
    }

    public function testSaveBotCreatesNewConfigWhenAbsent(): void
    {
        $path = $this->tmpDir . '/pb-migrate.json';
        ProjectConfig::saveBot($path, 'newbot', ['directory' => './aiml/newbot']);

        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['newbot' => ['directory' => './aiml/newbot']], $decoded['bots']);
    }

    public function testSaveBotAppendsToExistingConfig(): void
    {
        $path = $this->writeConfig(json_encode([
            'bots' => ['greeter' => ['directory' => './aiml/greeter']],
        ], JSON_THROW_ON_ERROR));

        ProjectConfig::saveBot($path, 'support', ['directory' => './aiml/support']);

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $decoded['bots']);
        $this->assertArrayHasKey('greeter', $decoded['bots']);
        $this->assertArrayHasKey('support', $decoded['bots']);
    }

    public function testRemoveBotEliminatesEntry(): void
    {
        $path = $this->writeConfig(json_encode([
            'bots' => [
                'greeter' => ['directory' => './aiml/greeter'],
                'support' => ['directory' => './aiml/support'],
            ],
        ], JSON_THROW_ON_ERROR));

        ProjectConfig::removeBot($path, 'greeter');

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('greeter', $decoded['bots']);
        $this->assertArrayHasKey('support', $decoded['bots']);
    }
}
