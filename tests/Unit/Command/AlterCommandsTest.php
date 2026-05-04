<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use KnLab\PbMigrate\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AlterCommandsTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;
    private string $variantPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-alter-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/aiml/mybot', 0o755, true);
        mkdir($this->tmpDir . '/variants', 0o755, true);

        $this->configPath = $this->tmpDir . '/pb-migrate.json';
        $this->variantPath = $this->tmpDir . '/variants/dump.aiml';
        file_put_contents($this->variantPath, "<aiml/>\n");

        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

        file_put_contents($this->configPath, json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => [
                'mybot' => ['directory' => './aiml/mybot'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testAlterListReportsEmptyWhenNoneConfigured(): void
    {
        $tester = new CommandTester($this->app()->find('alter:list'));
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('mybot', $tester->getDisplay());
        $this->assertStringContainsString('(no alters)', $tester->getDisplay());
    }

    public function testAlterSetWritesEntryAndStoresRelativePath(): void
    {
        $tester = new CommandTester($this->app()->find('alter:set'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            'name' => 'greet',
            'path' => 'variants/dump.aiml',
        ]);
        $tester->assertCommandIsSuccessful();

        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['greet' => 'variants/dump.aiml'], $decoded['bots']['mybot']['alters']);
    }

    public function testAlterSetPreservesEnvVarLiteralsInConfig(): void
    {
        $tester = new CommandTester($this->app()->find('alter:set'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            'name' => 'greet',
            'path' => 'variants/dump.aiml',
        ]);
        $tester->assertCommandIsSuccessful();

        $rawAfter = (string) file_get_contents($this->configPath);
        $this->assertStringContainsString('${PB_APP_ID}', $rawAfter, 'env-var literal must survive write-back');
        $this->assertStringContainsString('${PB_USER_KEY}', $rawAfter, 'env-var literal must survive write-back');
        $this->assertStringNotContainsString('app-x', $rawAfter, 'expanded value must not leak into the file');
    }

    public function testAlterSetRejectsMissingPath(): void
    {
        $tester = new CommandTester($this->app()->find('alter:set'));
        $this->expectException(\KnLab\PbMigrate\Exception\ConfigException::class);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            'name' => 'greet',
            'path' => 'variants/does-not-exist.aiml',
        ]);
    }

    public function testAlterUnsetRemovesEntry(): void
    {
        $this->seedAlters(['greet' => 'variants/dump.aiml']);

        $tester = new CommandTester($this->app()->find('alter:unset'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            'name' => 'greet',
        ]);
        $tester->assertCommandIsSuccessful();

        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('alters', $decoded['bots']['mybot'], 'empty alters map must be dropped');
    }

    public function testAlterUnsetIsNoOpForUnknownName(): void
    {
        $tester = new CommandTester($this->app()->find('alter:unset'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            'name' => 'nonexistent',
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('not set', $tester->getDisplay());
    }

    public function testAlterResetClearsAllAlters(): void
    {
        $this->seedAlters([
            'greet' => 'variants/dump.aiml',
            'fallback' => 'variants/dump.aiml',
        ]);

        $tester = new CommandTester($this->app()->find('alter:reset'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--yes' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('alters', $decoded['bots']['mybot']);
    }

    public function testAlterListShowsEntriesAfterSet(): void
    {
        $this->seedAlters([
            'greet' => 'variants/dump.aiml',
            'fallback' => 'variants/dump.aiml',
        ]);

        $tester = new CommandTester($this->app()->find('alter:list'));
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('greet', $display);
        $this->assertStringContainsString('fallback', $display);
        $this->assertStringContainsString('variants/dump.aiml', $display);
    }

    /** @param array<string, string> $alters */
    private function seedAlters(array $alters): void
    {
        $decoded = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);
        $decoded['bots']['mybot']['alters'] = $alters;
        file_put_contents(
            $this->configPath,
            (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function app(): Application
    {
        return new Application();
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
