<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KnLab\PbMigrate\Application;
use KnLab\PbMigrate\PBClientFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the compile command — calls PBClient::compile() per bot,
 * supports --bot / --all multi-bot operation.
 */
final class CompileCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    /** @var list<array<string, mixed>> */
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-comp-' . bin2hex(random_bytes(4));
        $aimlA = $this->tmpDir . '/aiml/bot-a';
        $aimlB = $this->tmpDir . '/aiml/bot-b';
        mkdir($aimlA, 0o755, true);
        mkdir($aimlB, 0o755, true);

        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

        file_put_contents($this->configPath, (string) json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => [
                'bot-a' => ['directory' => $aimlA],
                'bot-b' => ['directory' => $aimlB],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testCompileSingleBotCallsVerifyEndpointOnce(): void
    {
        $tester = $this->commandTester('compile', [
            new Response(200, [], '{"status":"ok"}'),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'bot-a',
        ]);
        $tester->assertCommandIsSuccessful();

        $this->assertCount(1, $this->requestHistory);
        $req = $this->requestHistory[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/bot/app-x/bot-a/verify', $req->getUri()->getPath());

        $display = $tester->getDisplay();
        $this->assertStringContainsString('compiled bot-a', $display);
        $this->assertStringContainsString('Compiled 1 bot', $display);
    }

    public function testCompileAllInvokesEachBot(): void
    {
        $tester = $this->commandTester('compile', [
            new Response(200, [], '{"status":"ok"}'),
            new Response(200, [], '{"status":"ok"}'),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--all' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $paths = array_map(static fn ($t) => $t['request']->getUri()->getPath(), $this->requestHistory);
        $this->assertContains('/bot/app-x/bot-a/verify', $paths);
        $this->assertContains('/bot/app-x/bot-b/verify', $paths);
        $this->assertStringContainsString('Compiled 2 bot(s)', $tester->getDisplay());
    }

    /** @param list<\Psr\Http\Message\ResponseInterface> $responses */
    private function commandTester(string $name, array $responses): CommandTester
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->requestHistory = [];
        $stack->push(Middleware::history($this->requestHistory));
        $http = new Client(['handler' => $stack]);
        $app = new Application('pb-migrate', '0.1.0', new PBClientFactory($http));
        return new CommandTester($app->find($name));
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
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
