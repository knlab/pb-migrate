<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KnLab\PbMigrate\Application;
use KnLab\PbMigrate\PBClientFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class FileDeleteCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;
    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-fd-' . bin2hex(random_bytes(4));
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
        @unlink($this->tmpDir . '/.pb-migrate-cache.json');
        @rmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    private function tester(MockHandler $mock): CommandTester
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $http = new Client(['handler' => $stack]);
        $app = new Application('pb-migrate', '0.4.0', new PBClientFactory($http));
        return new CommandTester($app->find('file:delete'));
    }

    public function testDeleteFileKindWithYesFlag(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"status":"ok"}')]);
        $tester = $this->tester($mock);

        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--kind' => 'file',
            '--yes' => true,
            'name' => 'greet',
        ]);

        $tester->assertCommandIsSuccessful();
        $this->assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/bot/app/mybot/file/greet', $req->getUri()->getPath());
    }

    public function testDeletePropertiesWithoutName(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"status":"ok"}')]);
        $tester = $this->tester($mock);

        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--kind' => 'properties',
            '--yes' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame('/bot/app/mybot/properties', $this->history[0]['request']->getUri()->getPath());
    }
}
