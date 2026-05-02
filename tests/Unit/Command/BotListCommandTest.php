<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KnLab\PbMigrate\Application;
use KnLab\PbMigrate\PBClientFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BotListCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-blist-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

        file_put_contents($this->configPath, json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => [],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @rmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testBotListPrintsTableFromMockedClient(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                ['botname' => 'a', 'language' => 'en', 'compiled' => true],
                ['botname' => 'b', 'language' => 'ja', 'compiled' => false],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);

        $app = new Application('pb-migrate', '0.1.0', new PBClientFactory($http));
        $tester = new CommandTester($app->find('bot:list'));
        $tester->execute(['--config' => $this->configPath]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('botname', $display);
        $this->assertStringContainsString('a', $display);
        $this->assertStringContainsString('b', $display);
    }
}
