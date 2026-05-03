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

final class BotFilesCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-bf-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app');
        putenv('PB_USER_KEY=key');
        file_put_contents($this->configPath, json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => ['mybot' => ['directory' => './aiml/mybot']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @rmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testBotFilesPrintsTableForEachKind(): void
    {
        $body = json_encode([
            'files' => [['name' => 'greet.aiml', 'size' => 100, 'modified' => '2026-05-03']],
            'sets' => [['name' => 'colors', 'size' => 30, 'modified' => '2026-05-02']],
            'maps' => [],
            'substitutions' => [],
            'pdefaults' => [],
            'properties' => [],
        ], JSON_THROW_ON_ERROR);

        $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
        $app = new Application('pb-migrate', '0.4.0', new PBClientFactory($http));
        $tester = new CommandTester($app->find('bot:files'));

        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('AIML', $display);
        $this->assertStringContainsString('greet.aiml', $display);
        $this->assertStringContainsString('Sets', $display);
        $this->assertStringContainsString('colors', $display);
        $this->assertStringContainsString('2 file(s) total', $display);
    }

    public function testBotFilesShowsNoFilesForEmptyResponse(): void
    {
        $body = json_encode([
            'files' => [],
            'sets' => [],
            'maps' => [],
            'substitutions' => [],
            'pdefaults' => [],
            'properties' => [],
        ], JSON_THROW_ON_ERROR);

        $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
        $app = new Application('pb-migrate', '0.4.0', new PBClientFactory($http));
        $tester = new CommandTester($app->find('bot:files'));

        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('(no files)', $tester->getDisplay());
    }
}
