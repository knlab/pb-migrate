<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use KnLab\PbMigrate\Application;
use KnLab\PbMigrate\Sync\CacheStore;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\FileKind;
use Symfony\Component\Console\Tester\CommandTester;

final class ReportCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;
    private string $localDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-rep-' . bin2hex(random_bytes(4));
        $this->localDir = $this->tmpDir . '/aiml/mybot';
        mkdir($this->localDir, 0o755, true);

        $this->configPath = $this->tmpDir . '/pb-migrate.json';
        putenv('PB_APP_ID=app-x');
        putenv('PB_USER_KEY=key-x');

        file_put_contents($this->configPath, json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => [
                'mybot' => ['directory' => $this->localDir],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    public function testSinceCacheReportsAddForFileWithoutCacheEntry(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml/>\n");

        $tester = new CommandTester((new Application())->find('report'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--since' => 'cache',
        ]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('no cache reference yet', $display, 'should warn that cache is empty');
        $this->assertStringContainsString('Local changes for bot', $display, 'cache mode should use the local-changes heading');
        $this->assertStringContainsString('(+) file/greet', $display);
    }

    public function testSinceCacheReportsUpdateWhenLocalHashDiffersFromCache(): void
    {
        $path = $this->localDir . '/greet.aiml';
        file_put_contents($path, "<aiml/>\n");
        $this->seedCacheEntry('mybot', FileKind::File, 'greet', hash('sha256', 'old-body'));

        $tester = new CommandTester((new Application())->find('report'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--since' => 'cache',
        ]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('(*) file/greet', $display);
        $this->assertStringNotContainsString('no cache reference yet', $display);
    }

    public function testSinceCacheReportsDeleteWhenCacheHasEntryWithoutLocalFile(): void
    {
        // No local greet.aiml; cache has it.
        $this->seedCacheEntry('mybot', FileKind::File, 'greet', hash('sha256', 'old-body'));

        $tester = new CommandTester((new Application())->find('report'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--since' => 'cache',
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('(-) file/greet', $tester->getDisplay());
    }

    public function testSinceCacheRejectsFullCheckCombo(): void
    {
        $tester = new CommandTester((new Application())->find('report'));
        $this->expectException(\KnLab\PbMigrate\Exception\ConfigException::class);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--since' => 'cache',
            '--full-check' => true,
        ]);
    }

    public function testSinceUnknownValueRejected(): void
    {
        $tester = new CommandTester((new Application())->find('report'));
        $this->expectException(\KnLab\PbMigrate\Exception\ConfigException::class);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--since' => 'whatever',
        ]);
    }

    public function testSinceCacheCleanWhenLocalMatchesCache(): void
    {
        $path = $this->localDir . '/greet.aiml';
        $body = "<aiml version=\"2.0\"/>\n";
        file_put_contents($path, $body);
        $this->seedCacheEntry('mybot', FileKind::File, 'greet', hash('sha256', $body));

        $tester = new CommandTester((new Application())->find('report'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--since' => 'cache',
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('(no pending changes)', $tester->getDisplay());
    }

    private function seedCacheEntry(string $botname, FileKind $kind, string $name, string $hash): void
    {
        $cache = CacheStore::forProjectRoot($this->tmpDir);
        $cache->set($botname, $kind, $name, $hash);
        $cache->save();
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
