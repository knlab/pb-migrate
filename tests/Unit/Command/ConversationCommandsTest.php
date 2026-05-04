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
 * Covers the three thin conversation-wrapper commands: talk, debug, atalk.
 * They are similar enough to share fixtures and helpers — splitting into
 * separate files would duplicate ~80 lines of boilerplate per test class.
 */
final class ConversationCommandsTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    /** @var list<array<string, mixed>> */
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-conv-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');
        putenv('PB_BOT_KEY=botkey-x');

        file_put_contents($this->configPath, (string) json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'botKey' => '${PB_BOT_KEY:-}',
            'bots' => [
                'mybot' => ['directory' => $this->tmpDir . '/aiml/mybot'],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @rmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
        putenv('PB_BOT_KEY');
    }

    // ---- talk ----

    public function testTalkPrintsResponses(): void
    {
        $tester = $this->commandTester('talk', [
            new Response(200, [], (string) json_encode([
                'status' => 'ok',
                'responses' => ['Hello, world.'],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            'input' => 'HELLO',
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Hello, world.', $tester->getDisplay());

        $req = $this->requestHistory[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/talk/app-x/mybot', $req->getUri()->getPath());
    }

    public function testTalkPassesClientNameAndSessionToApi(): void
    {
        $tester = $this->commandTester('talk', [
            new Response(200, [], (string) json_encode([
                'status' => 'ok', 'responses' => ['ok'],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            'input' => 'HI',
            '--client-name' => 'alice',
            '--session' => 'sess-42',
        ]);
        $tester->assertCommandIsSuccessful();

        $body = (string) $this->requestHistory[0]['request']->getBody();
        $this->assertStringContainsString('client_name=alice', $body);
        $this->assertStringContainsString('sessionid=sess-42', $body);
    }

    // ---- debug ----

    public function testDebugPrintsTraceJsonByDefault(): void
    {
        $payload = ['status' => 'ok', 'responses' => ['ok'], 'trace' => ['matched' => 'HELLO']];
        $tester = $this->commandTester('debug', [
            new Response(200, [], (string) json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            'input' => 'HELLO',
        ]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('"trace"', $display, 'debug must print pretty-printed JSON including trace');
        $this->assertStringContainsString('"matched"', $display);
        $this->assertStringContainsString('HELLO', $display);
    }

    public function testDebugRequestIncludesTraceAndExtraFlags(): void
    {
        $tester = $this->commandTester('debug', [
            new Response(200, [], (string) json_encode([
                'status' => 'ok', 'responses' => ['ok'], 'trace' => [],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            'input' => 'HELLO',
            '--extra' => true,
            '--reset' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $body = (string) $this->requestHistory[0]['request']->getBody();
        $this->assertStringContainsString('trace=true', $body);
        $this->assertStringContainsString('extra=true', $body);
        $this->assertStringContainsString('reset=true', $body);
    }

    // ---- atalk ----

    public function testAtalkPrintsResponseAndUsesBotKeyEndpoint(): void
    {
        $tester = $this->commandTester('atalk', [
            new Response(200, [], (string) json_encode([
                'status' => 'ok', 'responses' => ['anonymous hello'],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            'input' => 'HI',
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('anonymous hello', $tester->getDisplay());

        $req = $this->requestHistory[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/talk', $req->getUri()->getPath(), 'atalk uses /talk?botkey=... endpoint');
        $this->assertStringContainsString('botkey=botkey-x', $req->getUri()->getQuery());
    }

    public function testAtalkFailsWhenBotKeyIsAbsent(): void
    {
        // Re-write config WITHOUT a botKey; rely on PB_BOT_KEY:-default empty.
        putenv('PB_BOT_KEY');
        file_put_contents($this->configPath, (string) json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'botKey' => '${PB_BOT_KEY:-}',
            'bots' => [
                'mybot' => ['directory' => $this->tmpDir . '/aiml/mybot'],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $tester = $this->commandTester('atalk', []);  // no responses queued — must not be reached
        $tester->execute([
            '--config' => $this->configPath,
            'input' => 'HI',
        ]);
        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('atalk requires PB_BOT_KEY', $tester->getDisplay());
        $this->assertSame([], $this->requestHistory, 'atalk must not call the API when botKey is missing');
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
}
