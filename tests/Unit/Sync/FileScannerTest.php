<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Sync;

use KnLab\PbMigrate\Config\BotConfig;
use KnLab\PbMigrate\Sync\FileScanner;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\FileKind;

final class FileScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-scan-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
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
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testScanReturnsLocalFilesOfRecognisedKinds(): void
    {
        file_put_contents($this->tmpDir . '/greet.aiml', '<aiml>x</aiml>');
        file_put_contents($this->tmpDir . '/colors.set', 'red,green,blue');
        file_put_contents($this->tmpDir . '/synonyms.map', 'a:b');
        file_put_contents($this->tmpDir . '/sub.substitution', 's:t');
        file_put_contents($this->tmpDir . '/foo.properties', 'k=v');
        file_put_contents($this->tmpDir . '/notes.txt', 'irrelevant');

        $bot = new BotConfig('mybot', $this->tmpDir);
        $files = (new FileScanner())->scan($bot);

        $kinds = array_map(static fn ($f) => $f->kind, $files);
        $this->assertCount(5, $files, 'five recognised kinds, the txt file is skipped');
        $this->assertContains(FileKind::File, $kinds);
        $this->assertContains(FileKind::Set, $kinds);
        $this->assertContains(FileKind::Map, $kinds);
        $this->assertContains(FileKind::Substitution, $kinds);
        $this->assertContains(FileKind::Properties, $kinds);
    }

    public function testScanComputesSha256(): void
    {
        $path = $this->tmpDir . '/greet.aiml';
        file_put_contents($path, 'hello');

        $files = (new FileScanner())->scan(new BotConfig('mybot', $this->tmpDir));
        $this->assertCount(1, $files);
        $this->assertSame(hash('sha256', 'hello'), $files[0]->hash);
        $this->assertSame('greet', $files[0]->name);
    }

    public function testScanReturnsEmptyForMissingDirectory(): void
    {
        $files = (new FileScanner())->scan(new BotConfig('mybot', $this->tmpDir . '/nope'));
        $this->assertSame([], $files);
    }
}
