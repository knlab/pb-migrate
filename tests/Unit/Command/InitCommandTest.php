<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use KnLab\PbMigrate\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InitCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-init-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
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
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testInitCreatesSkeleton(): void
    {
        $app = new Application();
        $command = $app->find('init');
        $tester = new CommandTester($command);

        $tester->execute([
            'directory' => $this->tmpDir,
            'botname' => 'mybot',
        ]);

        $tester->assertCommandIsSuccessful();
        $this->assertFileExists($this->tmpDir . '/pb-migrate.json');
        $this->assertFileExists($this->tmpDir . '/.env.example');
        $this->assertFileExists($this->tmpDir . '/aiml/mybot/greetings.aiml');

        $config = json_decode((string) file_get_contents($this->tmpDir . '/pb-migrate.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['mybot' => ['directory' => './aiml/mybot', 'files' => '*']], $config['bots']);
        $this->assertSame('${PB_APP_ID}', $config['appId']);
    }

    public function testInitDoesNotOverwriteExistingFiles(): void
    {
        mkdir($this->tmpDir, 0o755, true);
        file_put_contents($this->tmpDir . '/pb-migrate.json', '{"keep":"me"}');

        $app = new Application();
        $tester = new CommandTester($app->find('init'));
        $tester->execute(['directory' => $this->tmpDir, 'botname' => 'mybot']);

        $this->assertSame('{"keep":"me"}', file_get_contents($this->tmpDir . '/pb-migrate.json'));
    }
}
