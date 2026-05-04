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
 * Covers the `pull` command. HTTP layer mocked.
 *
 * Pull's call sequence:
 *   1. GET /bot/{appId}/{botname}                              (getBotFiles)
 *   2. GET /bot/{appId}/{botname}/<kind>/<name>  per file      (getBotFile body)
 *
 * Verified behaviours:
 *   - Files are written to the configured bot directory
 *   - AIML kind restores the .aiml extension; properties stays as bare "properties"
 *   - --only filters which files are downloaded
 *   - Missing local directory is created on demand
 *   - 404 on getBotFile is reported as a skip and does not abort the pull
 */
final class PullCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;
    private string $localDir;

    /** @var list<array<string, mixed>> */
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-pull-' . bin2hex(random_bytes(4));
        $this->localDir = $this->tmpDir . '/aiml/mybot';
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

        // Note: localDir intentionally NOT created in setUp — one test asserts
        // that pull creates it when missing.
        mkdir($this->tmpDir, 0o755, true);

        file_put_contents($this->configPath, (string) json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => [
                'mybot' => ['directory' => $this->localDir],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testPullDownloadsAimlFileAndRestoresExtension(): void
    {
        $body = "<aiml/>\n";
        $tester = $this->commandTester('pull', [
            $this->okGetBotFiles(['files' => [['name' => 'greet.aiml']]]),
            new Response(200, [], $body),
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $this->assertFileExists($this->localDir . '/greet.aiml', 'pull should write greet.aiml with the .aiml extension restored');
        $this->assertSame($body, (string) file_get_contents($this->localDir . '/greet.aiml'));
        $this->assertStringContainsString('Pulled 1 file', $tester->getDisplay());
    }

    public function testPullDownloadsPropertiesAsBareKindName(): void
    {
        $body = '[["botname", "MyBot"]]';
        $tester = $this->commandTester('pull', [
            $this->okGetBotFiles(['properties' => [['name' => 'properties']]]),
            new Response(200, [], $body),
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $this->assertFileExists($this->localDir . '/properties', 'properties kind should be written as bare "properties"');
        $this->assertSame($body, (string) file_get_contents($this->localDir . '/properties'));
    }

    public function testPullCreatesLocalDirectoryWhenMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->localDir);

        $tester = $this->commandTester('pull', [
            $this->okGetBotFiles(['files' => [['name' => 'greet.aiml']]]),
            new Response(200, [], "<aiml/>\n"),
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $this->assertDirectoryExists($this->localDir);
        $this->assertFileExists($this->localDir . '/greet.aiml');
    }

    public function testOnlyRestrictsTargets(): void
    {
        $tester = $this->commandTester('pull', [
            $this->okGetBotFiles(['files' => [
                ['name' => 'greet.aiml'],
                ['name' => 'farewell.aiml'],
            ]]),
            new Response(200, [], "greet body\n"),
            // No body queued for farewell — must not be requested.
        ]);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--only' => 'greet',
        ]);
        $tester->assertCommandIsSuccessful();

        $this->assertFileExists($this->localDir . '/greet.aiml');
        $this->assertFileDoesNotExist($this->localDir . '/farewell.aiml', '--only=greet must skip farewell');

        $paths = array_map(static fn ($t) => $t['request']->getUri()->getPath(), $this->requestHistory);
        $this->assertNotContains('/bot/app-x/mybot/file/farewell', $paths, '--only must avoid the API call for skipped files');
    }

    public function testSkipsFileWhenServerReturns404(): void
    {
        // Simulating Pandorabots' system-managed `udc` file which is listed by
        // getBotFiles but cannot be downloaded by clients.
        $tester = $this->commandTester('pull', [
            $this->okGetBotFiles(['files' => [
                ['name' => 'greet.aiml'],
                ['name' => 'udc'],
            ]]),
            new Response(200, [], "<aiml/>\n"),
            new Response(404, [], '{"status":"error","message":"not found"}'),
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $this->assertFileExists($this->localDir . '/greet.aiml');
        $this->assertFileDoesNotExist($this->localDir . '/udc.aiml', 'a 404 must skip the file, not crash the pull');
        $this->assertStringContainsString('skip file/udc', $tester->getDisplay());
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

    /** @param array<string, list<array<string, string>>> $contents */
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
