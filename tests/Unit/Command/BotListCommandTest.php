<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use KnLab\PbMigrate\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Post-v0.7: bot:list now shows LOCALLY-REGISTERED bots only (no API call).
 * For an account-wide remote view, see bot:remote.
 */
final class BotListCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-blist-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @rmdir($this->tmpDir);
    }

    public function testBotListShowsRegisteredBots(): void
    {
        file_put_contents($this->configPath, (string) json_encode([
            'bots' => [
                'greeter' => ['directory' => './aiml/greeter'],
                'support' => ['directory' => './aiml/support', 'propertiesUpload' => 'full'],
            ],
        ], JSON_THROW_ON_ERROR));

        $tester = new CommandTester((new Application())->find('bot:list'));
        $tester->execute(['--config' => $this->configPath]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('greeter', $display);
        $this->assertStringContainsString('support', $display);
        $this->assertStringContainsString('full', $display, 'propertiesUpload column should reflect "full"');
    }

    public function testBotListEmpty(): void
    {
        file_put_contents($this->configPath, (string) json_encode([
            'bots' => [],
        ], JSON_THROW_ON_ERROR));

        $tester = new CommandTester((new Application())->find('bot:list'));
        $tester->execute(['--config' => $this->configPath]);
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString('no bots registered', $tester->getDisplay());
    }
}
