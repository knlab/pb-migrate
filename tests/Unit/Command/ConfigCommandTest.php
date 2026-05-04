<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use KnLab\PbMigrate\Application;
use KnLab\PbMigrate\Config\EnvFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;
    private string $envPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-cfgc-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';
        $this->envPath = $this->tmpDir . '/.env';

        file_put_contents($this->configPath, (string) json_encode([
            'bots' => [
                'mybot' => ['directory' => './aiml/mybot'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @unlink($this->envPath);
        @rmdir($this->tmpDir);
        foreach (['PB_APP_ID', 'PB_USER_KEY', 'PB_HOST', 'PB_BOT_MYBOT_KEY'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    public function testConfigSetsProjectCredentialsViaFlags(): void
    {
        $tester = new CommandTester((new Application())->find('config'));
        $tester->execute([
            '--config' => $this->configPath,
            '--app-id' => 'app-XYZ',
            '--user-key' => 'key-ABC',
        ]);
        $tester->assertCommandIsSuccessful();

        $f = new EnvFile($this->envPath);
        $this->assertSame(
            ['PB_APP_ID' => 'app-XYZ', 'PB_USER_KEY' => 'key-ABC'],
            $f->readBlock('app'),
        );
    }

    public function testConfigSetsBotKeyViaFlags(): void
    {
        $tester = new CommandTester((new Application())->find('config'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--bot-key' => 'secret-mybot-key',
        ]);
        $tester->assertCommandIsSuccessful();

        $f = new EnvFile($this->envPath);
        $this->assertSame(
            ['PB_BOT_MYBOT_KEY' => 'secret-mybot-key'],
            $f->readBlock('bot=mybot'),
        );
    }

    public function testConfigShowPrintsValuesInPlainText(): void
    {
        // `config --show` is the explicit "let me see what's stored" entry
        // point, so it prints credentials in plaintext — masking would fight
        // the user's intent. (The `feedback_credential_display_in_editor.md`
        // memory captures the design rationale.)
        $f = new EnvFile($this->envPath);
        $f->writeBlock('app', ['PB_APP_ID' => 'app-secret-1234567890', 'PB_USER_KEY' => 'key-very-secret-abc']);

        $tester = new CommandTester((new Application())->find('config'));
        $tester->execute([
            '--config' => $this->configPath,
            '--show' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('app-secret-1234567890', $display);
        $this->assertStringContainsString('key-very-secret-abc', $display);
        $this->assertStringContainsString('PB_APP_ID', $display);
        $this->assertStringContainsString('PB_USER_KEY', $display);
    }

    public function testConfigBotEmptyKeyRemovesBlock(): void
    {
        $f = new EnvFile($this->envPath);
        $f->writeBlock('bot=mybot', ['PB_BOT_MYBOT_KEY' => 'old-key']);

        $tester = new CommandTester((new Application())->find('config'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--bot-key' => '',  // empty = remove
        ]);
        $tester->assertCommandIsSuccessful();

        $fAfter = new EnvFile($this->envPath);
        $this->assertNull($fAfter->readBlock('bot=mybot'));
    }
}
