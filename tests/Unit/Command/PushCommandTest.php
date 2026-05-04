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
 * Covers the orchestration in PushCommand. HTTP layer is mocked so no API
 * access is required. The mock queue order matters and follows the actual
 * call sequence inside push:
 *   1. getBotFiles()              GET  /bot/{appId}/{botname}
 *   2. (optionally getBotFile()   GET  /bot/{appId}/{botname}/<kind>/<name>  for UPDATE detection)
 *   3. deleteBotFile() / upload() DELETE / PUT for each detected change
 *   4. compile()                  GET  /bot/{appId}/{botname}/verify  (unless --skip-compile)
 */
final class PushCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;
    private string $localDir;

    /** @var list<array<string, mixed>> */
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-push-' . bin2hex(random_bytes(4));
        $this->localDir = $this->tmpDir . '/aiml/mybot';
        $this->variantsDir = $this->tmpDir . '/variants';
        mkdir($this->localDir, 0o755, true);
        mkdir($this->variantsDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

        $this->writeConfig([]);
    }

    private string $variantsDir;

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testAddsNewFileUploadsThenCompiles(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml/>\n");

        $tester = $this->commandTester('push', [
            $this->okGetBotFiles(),       // empty remote
            $this->okStatus(),            // upload greet
            $this->okStatus(),            // compile
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('[add] file/greet', $display);
        $this->assertStringContainsString('Pushed 1 change', $display);

        $paths = $this->requestPaths();
        $this->assertContains('/bot/app-x/mybot', $paths, 'should call getBotFiles');
        $this->assertContains('/bot/app-x/mybot/file/greet', $paths, 'should upload greet');
        $this->assertContains('/bot/app-x/mybot/verify', $paths, 'should compile');
    }

    public function testReportsNoChangesWhenLocalMatchesRemote(): void
    {
        $body = "<aiml/>\n";
        file_put_contents($this->localDir . '/greet.aiml', $body);

        $tester = $this->commandTester('push', [
            $this->okGetBotFiles(['files' => [['name' => 'greet.aiml']]]),
            // DiffEngine fetches the remote body to compare hashes
            // (no cache entry exists in this fresh project).
            new Response(200, [], $body),
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('no changes', $tester->getDisplay());
    }

    public function testDryRunDoesNotUploadOrCompile(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml/>\n");

        $tester = $this->commandTester('push', [
            $this->okGetBotFiles(),
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--dry-run' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString('dry run', $tester->getDisplay());
        $this->assertStringContainsString('[add] file/greet', $tester->getDisplay());

        $paths = $this->requestPaths();
        $this->assertNotContains('/bot/app-x/mybot/file/greet', $paths, 'dry-run must not upload');
        $this->assertNotContains('/bot/app-x/mybot/verify', $paths, 'dry-run must not compile');
    }

    public function testSkipCompileOmitsCompileCall(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml/>\n");

        $tester = $this->commandTester('push', [
            $this->okGetBotFiles(),
            $this->okStatus(),  // upload
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--skip-compile' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $paths = $this->requestPaths();
        $this->assertContains('/bot/app-x/mybot/file/greet', $paths);
        $this->assertNotContains('/bot/app-x/mybot/verify', $paths, '--skip-compile must skip compile');
    }

    public function testDeletesRemoteOnlyFilesByDefault(): void
    {
        // Local has nothing; remote reports an old greet. Default behaviour
        // (no flags) is destructive: remote-only files are deleted.
        $tester = $this->commandTester('push', [
            $this->okGetBotFiles(['files' => [['name' => 'greet.aiml']]]),
            $this->okStatus(),  // delete greet
            $this->okStatus(),  // compile
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
        ]);
        $tester->assertCommandIsSuccessful();

        $methods = array_map(static fn ($t) => $t['request']->getMethod(), $this->requestHistory);
        $this->assertContains('DELETE', $methods, 'default push behaviour must delete remote-only files');
    }

    public function testKeepRemoteOnlyPreservesRemoteOnlyFiles(): void
    {
        $tester = $this->commandTester('push', [
            $this->okGetBotFiles(['files' => [['name' => 'greet.aiml']]]),
            // No upload / delete responses queued — must not be called.
            $this->okStatus(),  // compile (push still runs even if nothing is sent because there's a delete entry that's reported as skipped)
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--keep-remote-only' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('skipped', $display, 'should mention skip when --keep-remote-only');
        $methods = array_map(static fn ($t) => $t['request']->getMethod(), $this->requestHistory);
        $this->assertNotContains('DELETE', $methods, 'no DELETE with --keep-remote-only');
    }

    public function testOverrideUploadsUnderCanonicalName(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml>canonical</aiml>\n");
        file_put_contents($this->variantsDir . '/greet-debug.aiml', "<aiml>variant</aiml>\n");

        $tester = $this->commandTester('push', [
            $this->okGetBotFiles(),
            $this->okStatus(),  // upload
            $this->okStatus(),  // compile
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--override' => ['greet=' . $this->variantsDir . '/greet-debug.aiml'],
        ]);
        $tester->assertCommandIsSuccessful();

        $paths = $this->requestPaths();
        $this->assertContains(
            '/bot/app-x/mybot/file/greet',
            $paths,
            '--override must upload under the canonical name "greet", not the override path basename "greet-debug"',
        );
        $this->assertNotContains(
            '/bot/app-x/mybot/file/greet-debug',
            $paths,
            '--override must NOT upload as "greet-debug" — that was the bug fixed in v0.5.0',
        );
    }

    public function testOnlyRestrictsTargets(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml/>\n");
        file_put_contents($this->localDir . '/farewell.aiml', "<aiml/>\n");

        $tester = $this->commandTester('push', [
            $this->okGetBotFiles(),
            $this->okStatus(),  // single upload
            $this->okStatus(),  // compile
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--only' => 'greet',
        ]);
        $tester->assertCommandIsSuccessful();

        $paths = $this->requestPaths();
        $this->assertContains('/bot/app-x/mybot/file/greet', $paths);
        $this->assertNotContains('/bot/app-x/mybot/file/farewell', $paths, '--only=greet must not upload farewell');
    }

    // ------- helpers --------

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

    /** @param array<string, list<array<string, string>>> $contents kind-key → entries */
    private function okGetBotFiles(array $contents = []): Response
    {
        $payload = array_merge([
            'status' => 'ok',
            'files' => [],
            'sets' => [],
            'maps' => [],
            'substitutions' => [],
            'pdefaults' => [],
            'properties' => [],
        ], $contents);
        return new Response(200, [], (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function okStatus(): Response
    {
        return new Response(200, [], (string) json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> URL paths in request order */
    private function requestPaths(): array
    {
        return array_map(
            static fn (array $t) => $t['request']->getUri()->getPath(),
            $this->requestHistory,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function writeConfig(array $overrides): void
    {
        $base = [
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => [
                'mybot' => array_merge(['directory' => $this->localDir], $overrides),
            ],
        ];
        file_put_contents($this->configPath, (string) json_encode($base, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
