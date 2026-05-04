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

/**
 * Covers the `diff` command's output formatting. HTTP layer mocked.
 *
 * Verified branches:
 *   - no differences  → "(no differences)"
 *   - local-only file → "# local-only: <kind>/<name>"
 *   - remote-only file → "# remote-only: <kind>/<name>"
 *   - updated file → unified diff with --- remote/file/<name> +++ local/file/<name>
 */
final class DiffCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;
    private string $localDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-diff-' . bin2hex(random_bytes(4));
        $this->localDir = $this->tmpDir . '/aiml/mybot';
        mkdir($this->localDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

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

    public function testNoDifferencesWhenLocalAndRemoteMatch(): void
    {
        $body = "<aiml/>\n";
        file_put_contents($this->localDir . '/greet.aiml', $body);

        $tester = $this->commandTester('diff', [
            $this->okGetBotFiles(['files' => [['name' => 'greet.aiml']]]),
            new Response(200, [], $body),
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('(no differences)', $tester->getDisplay());
    }

    public function testReportsLocalOnlyForFileMissingFromRemote(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml/>\n");

        $tester = $this->commandTester('diff', [
            $this->okGetBotFiles(),  // empty remote
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('# local-only: file/greet', $tester->getDisplay());
    }

    public function testReportsRemoteOnlyForFileMissingLocally(): void
    {
        // No local greet; remote has it.
        $tester = $this->commandTester('diff', [
            $this->okGetBotFiles(['files' => [['name' => 'greet.aiml']]]),
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('# remote-only: file/greet', $tester->getDisplay());
    }

    public function testShowsUnifiedDiffForUpdatedFile(): void
    {
        $localBody = "<aiml>local-version</aiml>\n";
        $remoteBody = "<aiml>remote-version</aiml>\n";
        file_put_contents($this->localDir . '/greet.aiml', $localBody);

        $tester = $this->commandTester('diff', [
            $this->okGetBotFiles(['files' => [['name' => 'greet.aiml']]]),
            // DiffEngine fetches remote body to detect drift...
            new Response(200, [], $remoteBody),
            // ...then DiffCommand fetches it again to produce the unified output.
            new Response(200, [], $remoteBody),
        ]);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('--- remote/file/greet', $display);
        $this->assertStringContainsString('+++ local/file/greet', $display);
        $this->assertStringContainsString('-<aiml>remote-version</aiml>', $display);
        $this->assertStringContainsString('+<aiml>local-version</aiml>', $display);
    }

    /** @param list<\Psr\Http\Message\ResponseInterface> $responses */
    private function commandTester(string $name, array $responses): CommandTester
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
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
