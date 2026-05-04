<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use KnLab\PbMigrate\Application;
use KnLab\PbMigrate\Exception\ConfigException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AddCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-add-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/aiml/greeter', 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function testAddCreatesNewConfigAndRegistersBot(): void
    {
        $tester = new CommandTester((new Application())->find('add'));
        $tester->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/greeter',
        ]);
        $tester->assertCommandIsSuccessful();

        $this->assertFileExists($this->configPath);
        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('greeter', $decoded['bots']);
        $this->assertStringContainsString('Registered bot "greeter"', $tester->getDisplay());
    }

    public function testAddDerivesBotnameFromDirectoryWhenOmitted(): void
    {
        $tester = new CommandTester((new Application())->find('add'));
        $tester->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/greeter',
        ]);
        $tester->assertCommandIsSuccessful();

        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('greeter', $decoded['bots']);
    }

    public function testAddAcceptsExplicitBotnameViaFlag(): void
    {
        $tester = new CommandTester((new Application())->find('add'));
        $tester->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/greeter',
            '--bot' => 'mygreet',
        ]);
        $tester->assertCommandIsSuccessful();

        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('mygreet', $decoded['bots']);
    }

    public function testAddRejectsHyphenatedBotname(): void
    {
        $tester = new CommandTester((new Application())->find('add'));
        $this->expectException(ConfigException::class);
        $tester->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/greeter',
            '--bot' => 'my-bot',
        ]);
    }

    public function testAddRejectsMissingDirectory(): void
    {
        $tester = new CommandTester((new Application())->find('add'));
        $this->expectException(ConfigException::class);
        $tester->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/nonexistent',
        ]);
    }

    public function testAddRejectsDuplicateBotWithoutForce(): void
    {
        $tester = new CommandTester((new Application())->find('add'));
        $tester->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/greeter',
        ]);
        $tester->assertCommandIsSuccessful();

        $tester2 = new CommandTester((new Application())->find('add'));
        $tester2->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/greeter',
        ]);
        $this->assertNotSame(0, $tester2->getStatusCode());
        $this->assertStringContainsString('already registered', $tester2->getDisplay());
    }

    public function testAddExpandsGlobAndRegistersEachMatch(): void
    {
        mkdir($this->tmpDir . '/aiml/persona', 0o755, true);
        mkdir($this->tmpDir . '/aiml/support', 0o755, true);
        mkdir($this->tmpDir . '/aiml/unrelated', 0o755, true);

        $tester = new CommandTester((new Application())->find('add'));
        $tester->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/p*',
        ]);
        $tester->assertCommandIsSuccessful();

        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('persona', $decoded['bots']);
        $this->assertArrayNotHasKey('greeter', $decoded['bots'], 'greeter does not match p*');
        $this->assertArrayNotHasKey('support', $decoded['bots'], 'support does not match p*');
        $this->assertArrayNotHasKey('unrelated', $decoded['bots']);
    }

    public function testAddRejectsBotFlagWithMultiMatchGlob(): void
    {
        mkdir($this->tmpDir . '/aiml/persona', 0o755, true);
        mkdir($this->tmpDir . '/aiml/support', 0o755, true);

        $tester = new CommandTester((new Application())->find('add'));
        $this->expectException(ConfigException::class);
        $tester->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/*',
            '--bot' => 'collapse',
        ]);
    }

    public function testAddGlobWithNoMatchesIsAnError(): void
    {
        $tester = new CommandTester((new Application())->find('add'));
        $this->expectException(ConfigException::class);
        $tester->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/nope-*',
        ]);
    }

    public function testAddWithForceOverwritesDuplicate(): void
    {
        mkdir($this->tmpDir . '/aiml/greeter2', 0o755, true);

        $first = new CommandTester((new Application())->find('add'));
        $first->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/greeter',
            '--bot' => 'greeter',
        ]);
        $first->assertCommandIsSuccessful();

        $second = new CommandTester((new Application())->find('add'));
        $second->execute([
            '--config' => $this->configPath,
            'directory' => $this->tmpDir . '/aiml/greeter2',
            '--bot' => 'greeter',
            '--force' => true,
        ]);
        $second->assertCommandIsSuccessful();

        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('greeter2', $decoded['bots']['greeter']['directory']);
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
