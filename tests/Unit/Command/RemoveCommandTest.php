<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use KnLab\PbMigrate\Application;
use KnLab\PbMigrate\Config\EnvFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RemoveCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-rm-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/aiml/greeter', 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        file_put_contents($this->configPath, (string) json_encode([
            'bots' => [
                'greeter' => ['directory' => './aiml/greeter'],
                'support' => ['directory' => './aiml/support'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function testRemoveUnregistersBotFromConfig(): void
    {
        $tester = new CommandTester((new Application())->find('remove'));
        $tester->execute([
            '--config' => $this->configPath,
            'botname' => 'greeter',
            '--yes' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('greeter', $decoded['bots']);
        $this->assertArrayHasKey('support', $decoded['bots']);
    }

    public function testRemoveAlsoStripsBotKeyBlockFromEnv(): void
    {
        $envPath = $this->tmpDir . '/.env';
        $envFile = new EnvFile($envPath);
        $envFile->writeBlock('app', ['PB_APP_ID' => 'a']);
        $envFile->writeBlock('bot=greeter', ['PB_BOT_GREETER_KEY' => 'secret']);

        $tester = new CommandTester((new Application())->find('remove'));
        $tester->execute([
            '--config' => $this->configPath,
            'botname' => 'greeter',
            '--yes' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $envFileAfter = new EnvFile($envPath);
        $this->assertNull($envFileAfter->readBlock('bot=greeter'), 'bot block must be removed');
        $this->assertNotNull($envFileAfter->readBlock('app'), 'app block must be preserved');
    }

    public function testRemoveFailsForUnregisteredBot(): void
    {
        $tester = new CommandTester((new Application())->find('remove'));
        $tester->execute([
            '--config' => $this->configPath,
            'botname' => 'notregistered',
            '--yes' => true,
        ]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    public function testRemoveCancellationKeepsBotRegistered(): void
    {
        $tester = new CommandTester((new Application())->find('remove'));
        $tester->setInputs(['no']);
        $tester->execute([
            '--config' => $this->configPath,
            'botname' => 'greeter',
        ]);
        $tester->assertCommandIsSuccessful();

        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('greeter', $decoded['bots'], 'cancel must preserve registration');
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
