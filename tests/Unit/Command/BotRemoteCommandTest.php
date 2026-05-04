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

final class BotRemoteCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-rem-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

        file_put_contents($this->configPath, (string) json_encode([
            'bots' => [
                'greeter' => ['directory' => $this->tmpDir . '/aiml/greeter'],
                'support' => ['directory' => $this->tmpDir . '/aiml/support'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @rmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testBotRemoteAnnotatesRegisteredAndUnmanaged(): void
    {
        $tester = $this->commandTester([
            new Response(200, [], (string) json_encode([
                ['botname' => 'greeter', 'compiled' => true],
                ['botname' => 'support', 'compiled' => true],
                ['botname' => 'orphan', 'compiled' => false],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $tester->execute(['--config' => $this->configPath]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('greeter', $display);
        $this->assertStringContainsString('support', $display);
        $this->assertStringContainsString('orphan', $display);
        $this->assertStringContainsString('registered', $display);
        $this->assertStringContainsString('unmanaged', $display);
    }

    public function testBotRemoteShowsLocallyRegisteredButMissingRemoteSection(): void
    {
        $tester = $this->commandTester([
            new Response(200, [], (string) json_encode([
                // Only greeter on remote; support is registered but not yet created.
                ['botname' => 'greeter', 'compiled' => true],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $tester->execute(['--config' => $this->configPath]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Registered but not on remote', $display);
        $this->assertStringContainsString('support', $display);
        $this->assertStringContainsString('bot:create support', $display, 'should hint at bot:create');
    }

    /** @param list<\Psr\Http\Message\ResponseInterface> $responses */
    private function commandTester(array $responses): CommandTester
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $http = new Client(['handler' => $stack]);
        $app = new Application('pb-migrate', '0.1.0', new PBClientFactory($http));
        return new CommandTester($app->find('bot:remote'));
    }
}
