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
 * Covers bot:create and bot:delete — symmetric thin wrappers around
 * PBClient::create() / delete(). Bundled to share fixtures.
 */
final class BotLifecycleCommandsTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    /** @var list<array<string, mixed>> */
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-life-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

        file_put_contents($this->configPath, (string) json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => [],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @rmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testBotCreateCallsPutOnBotPathAndPrintsSuccess(): void
    {
        $tester = $this->commandTester('bot:create', [
            new Response(200, [], '{"status":"ok"}'),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            'botname' => 'shinybot',
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Created bot: shinybot', $tester->getDisplay());

        $req = $this->requestHistory[0]['request'];
        $this->assertSame('PUT', $req->getMethod());
        $this->assertSame('/bot/app-x/shinybot', $req->getUri()->getPath());
    }

    public function testBotDeleteWithYesSkipsConfirmAndIssuesDelete(): void
    {
        $tester = $this->commandTester('bot:delete', [
            new Response(200, [], '{"status":"ok"}'),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            'botname' => 'shinybot',
            '--yes' => true,
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Deleted bot: shinybot', $tester->getDisplay());

        $req = $this->requestHistory[0]['request'];
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/bot/app-x/shinybot', $req->getUri()->getPath());
    }

    public function testBotDeleteCancelledOnPromptIssuesNoApiCall(): void
    {
        // No responses queued — confirming "no" must keep the API untouched.
        $tester = $this->commandTester('bot:delete', []);
        $tester->setInputs(['no']);
        $tester->execute([
            '--config' => $this->configPath,
            'botname' => 'shinybot',
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Cancelled', $tester->getDisplay());
        $this->assertSame([], $this->requestHistory, 'cancellation must not issue a DELETE');
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
