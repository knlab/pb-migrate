<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use KnLab\PbMigrate\Application;
use KnLab\PbMigrate\Sync\CacheStore;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\FileKind;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * StatusCommand makes no API calls — it only compares local files against
 * the .pb-migrate-cache.json from the previous push/pull. These tests cover:
 *   - clean state (local matches cache exactly)
 *   - add/update count when something has been touched locally
 *   - --all default behaviour (no --bot, no --all → still operates on every bot)
 */
final class StatusCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;
    private string $localDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-stat-' . bin2hex(random_bytes(4));
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

    public function testCleanStateWhenLocalMatchesCache(): void
    {
        $body = "<aiml/>\n";
        file_put_contents($this->localDir . '/greet.aiml', $body);
        $this->seedCacheEntry('mybot', FileKind::File, 'greet', hash('sha256', $body));

        $tester = new CommandTester((new Application())->find('status'));
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('mybot', $display);
        $this->assertStringContainsString('in sync', $display);
        $this->assertStringNotContainsString('(+)', $display);
        $this->assertStringNotContainsString('(*)', $display);
    }

    public function testReportsAddCountWhenLocalHasNewFile(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml/>\n");
        // No cache entry → counted as ADD.

        $tester = new CommandTester((new Application())->find('status'));
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('(+) 1 add', $display);
        $this->assertStringNotContainsString('in sync', $display);
    }

    public function testReportsUpdateCountWhenLocalHashDiffers(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml version=\"2.0\"/>\n");
        $this->seedCacheEntry('mybot', FileKind::File, 'greet', hash('sha256', 'old-body'));

        $tester = new CommandTester((new Application())->find('status'));
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('(*) 1 update', $display);
    }

    public function testCombinedAddAndUpdateCounts(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml version=\"2.0\"/>\n");
        file_put_contents($this->localDir . '/farewell.aiml', "<aiml/>\n");
        $this->seedCacheEntry('mybot', FileKind::File, 'greet', hash('sha256', 'something else'));
        // farewell has no cache entry → ADD; greet has wrong hash → UPDATE.

        $tester = new CommandTester((new Application())->find('status'));
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('(+) 1 add', $display);
        $this->assertStringContainsString('(*) 1 update', $display);
    }

    public function testDefaultsToAllBotsWhenNeitherBotNorAllGiven(): void
    {
        // Add a second bot to the config so we can assert both appear in output.
        $secondDir = $this->tmpDir . '/aiml/otherbot';
        mkdir($secondDir, 0o755, true);
        file_put_contents($this->configPath, json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => [
                'mybot' => ['directory' => $this->localDir],
                'otherbot' => ['directory' => $secondDir],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $tester = new CommandTester((new Application())->find('status'));
        $tester->execute(['--config' => $this->configPath]); // no --bot, no --all
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('mybot', $display);
        $this->assertStringContainsString('otherbot', $display);
    }

    public function testIncludesUrlAndDirectoryInOutput(): void
    {
        file_put_contents($this->localDir . '/greet.aiml', "<aiml/>\n");

        $tester = new CommandTester((new Application())->find('status'));
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('URL:', $display);
        $this->assertStringContainsString('https://api.pandorabots.com', $display);
        $this->assertStringContainsString('directory:', $display);
        $this->assertStringContainsString($this->localDir, $display);
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
