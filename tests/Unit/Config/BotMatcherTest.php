<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Config;

use KnLab\PbMigrate\Config\BotMatcher;
use KnLab\PbMigrate\Config\ProjectConfig;
use KnLab\PbMigrate\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class BotMatcherTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-bm-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        putenv('PB_APP_ID=app');
        putenv('PB_USER_KEY=key');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/pb-migrate.json');
        @rmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    private function configWithBots(array $names): ProjectConfig
    {
        $bots = [];
        foreach ($names as $name) {
            $bots[$name] = ['directory' => './aiml/' . $name];
        }
        $path = $this->tmpDir . '/pb-migrate.json';
        file_put_contents($path, json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => $bots,
        ]));
        return ProjectConfig::load($path);
    }

    public function testResolveExactName(): void
    {
        $config = $this->configWithBots(['greeter', 'support']);
        $bots = BotMatcher::resolve($config, 'greeter');
        $this->assertCount(1, $bots);
        $this->assertSame('greeter', $bots[0]->name);
    }

    public function testResolveWildcardMatchesPrefix(): void
    {
        $config = $this->configWithBots(['prodgreeter', 'prodsupport', 'staginggreeter']);
        $bots = BotMatcher::resolve($config, 'prod*');
        $this->assertCount(2, $bots);
        $names = array_map(static fn ($b) => $b->name, $bots);
        $this->assertContains('prodgreeter', $names);
        $this->assertContains('prodsupport', $names);
    }

    public function testResolveAsteriskMatchesAll(): void
    {
        $config = $this->configWithBots(['a', 'b', 'c']);
        $this->assertCount(3, BotMatcher::resolve($config, '*'));
    }

    public function testResolveExactNameMissingThrows(): void
    {
        $config = $this->configWithBots(['greeter']);
        $this->expectException(ConfigException::class);
        BotMatcher::resolve($config, 'unknown');
    }

    public function testResolvePatternWithNoMatchesThrows(): void
    {
        $config = $this->configWithBots(['greeter']);
        $this->expectException(ConfigException::class);
        BotMatcher::resolve($config, 'prod*');
    }

    public function testAllReturnsEveryBot(): void
    {
        $config = $this->configWithBots(['a', 'b']);
        $this->assertCount(2, BotMatcher::all($config));
    }

    public function testAllThrowsWhenNoBotsConfigured(): void
    {
        $config = $this->configWithBots([]);
        $this->expectException(ConfigException::class);
        BotMatcher::all($config);
    }

    public function testLooksLikePattern(): void
    {
        $this->assertTrue(BotMatcher::looksLikePattern('a*'));
        $this->assertTrue(BotMatcher::looksLikePattern('*'));
        $this->assertFalse(BotMatcher::looksLikePattern('greeter'));
    }
}
