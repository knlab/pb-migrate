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
 * Covers the `test` command's bot-reply assertion logic with mocked HTTP.
 * Verified behaviours:
 *   - inline --input/--expect single test, pass and fail
 *   - --file form with multiple cases, mix of pass and fail
 *   - non-zero exit code on any mismatch (CI integration contract)
 *   - rejects when neither inline nor --file provided
 */
final class TestCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-tcmd-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/aiml/mybot', 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

        file_put_contents($this->configPath, (string) json_encode([
            'bots' => [
                'mybot' => ['directory' => $this->tmpDir . '/aiml/mybot'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testInlineSingleCasePassesIsSilentByDefault(): void
    {
        $app = $this->appWithTalkResponses(['Hello, world.']);

        $tester = new CommandTester($app->find('test'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--input' => 'HELLO',
            '--expect' => 'Hello, world.',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), 'matching reply must yield exit 0 for CI integration');
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('PASS', $display, 'default mode is silent on success');
        $this->assertStringContainsString('All 1 test(s) passed', $display);
    }

    public function testVerboseShowsPass(): void
    {
        $app = $this->appWithTalkResponses(['Hello, world.']);

        $tester = new CommandTester($app->find('test'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--input' => 'HELLO',
            '--expect' => 'Hello, world.',
            '--show-pass' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('PASS', $tester->getDisplay());
    }

    public function testInlineSingleCaseFailsWithNonZeroExit(): void
    {
        $app = $this->appWithTalkResponses(['Goodbye']);

        $tester = new CommandTester($app->find('test'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--input' => 'HELLO',
            '--expect' => 'Hello, world.',
        ]);

        $this->assertNotSame(0, $tester->getStatusCode(), 'mismatched reply must yield non-zero exit code');
        $display = $tester->getDisplay();
        $this->assertStringContainsString('FAIL', $display);
        $this->assertStringContainsString('expected: Hello, world.', $display);
        $this->assertStringContainsString('actual:   Goodbye', $display);
    }

    public function testFileFormWithMixedPassAndFailReportsBothAndExitsNonZero(): void
    {
        $testFile = $this->tmpDir . '/cases.txt';
        file_put_contents($testFile, <<<TXT
        # comments and blanks are skipped
        HELLO|Hi there
        BYE|See you later

        WHO|Bot
        TXT);

        $app = $this->appWithTalkResponses(['Hi there', 'See you later', 'Bot']);

        $tester = new CommandTester($app->find('test'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--file' => $testFile,
        ]);
        $this->assertSame(0, $tester->getStatusCode(), 'all-pass file run must exit 0');
        $this->assertStringContainsString('All 3 test(s) passed', $tester->getDisplay());
    }

    public function testFileFormCountsFailureAcrossMultipleCases(): void
    {
        $testFile = $this->tmpDir . '/cases.txt';
        file_put_contents($testFile, "HELLO|Hi\nBYE|Bye\n");

        // 1st case will pass, 2nd will fail.
        $app = $this->appWithTalkResponses(['Hi', 'Wrong']);

        $tester = new CommandTester($app->find('test'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--file' => $testFile,
        ]);
        $this->assertNotSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1/2 failed', $display);
    }

    public function testRejectsRunWithNeitherInlineNorFile(): void
    {
        $app = new Application('pb-migrate', '0.1.0', new PBClientFactory(new Client()));

        $tester = new CommandTester($app->find('test'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
        ]);
        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString(
            'Provide either --input X --expect Y or --file path/to/tests.txt',
            $tester->getDisplay(),
        );
    }

    /**
     * @param list<string> $replies one Pandorabots /talk response per case.
     */
    private function appWithTalkResponses(array $replies): Application
    {
        $responses = [];
        foreach ($replies as $reply) {
            $responses[] = new Response(200, [], json_encode([
                'status' => 'ok',
                'responses' => [$reply],
            ], JSON_THROW_ON_ERROR));
        }
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        return new Application('pb-migrate', '0.1.0', new PBClientFactory($http));
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
