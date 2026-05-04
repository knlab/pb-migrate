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

    public function testScanRecognisesBareNamePropertiesAndPdefaults(): void
    {
        // The API returns properties/pdefaults without a filename in their
        // path, and `pull` writes them as bare-name files. The scanner has to
        // recognise that shape so push picks them up symmetrically.
        file_put_contents($this->tmpDir . '/properties', 'k=v');
        file_put_contents($this->tmpDir . '/pdefaults', '[]');

        $files = (new FileScanner())->scan(new BotConfig('mybot', $this->tmpDir));
        $this->assertCount(2, $files);

        $byKind = [];
        foreach ($files as $f) {
            $byKind[$f->kind->value] = $f;
        }

        $this->assertArrayHasKey('properties', $byKind);
        $this->assertSame(FileKind::Properties, $byKind['properties']->kind);
        $this->assertSame('', $byKind['properties']->name, 'kinds without filename in path use empty name');

        $this->assertArrayHasKey('pdefaults', $byKind);
        $this->assertSame(FileKind::Pdefaults, $byKind['pdefaults']->kind);
        $this->assertSame('', $byKind['pdefaults']->name);
    }

    public function testScanSkipsExtensionlessFilesThatAreNotPropertiesOrPdefaults(): void
    {
        // A file literally named `set` (which IS a known kind, but Set requires
        // a filename in the path) must not be silently picked up as a Set entry
        // with empty name — it'd be invalid. Skip it.
        file_put_contents($this->tmpDir . '/set', 'noise');
        file_put_contents($this->tmpDir . '/random', 'noise');

        $files = (new FileScanner())->scan(new BotConfig('mybot', $this->tmpDir));
        $this->assertSame([], $files);
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

    public function testOverrideReplacesExistingFileBody(): void
    {
        $orig = $this->tmpDir . '/greet.aiml';
        file_put_contents($orig, 'production');

        // Put the variant outside the bot's scan directory.
        $variantDir = $this->tmpDir . '-variants';
        mkdir($variantDir);
        $variant = $variantDir . '/greet-test.aiml';
        file_put_contents($variant, 'test-variant');

        try {
            $bot = new BotConfig('mybot', $this->tmpDir);
            $files = (new FileScanner())->scan($bot, ['greet' => $variant]);

            $this->assertCount(1, $files, 'override should replace, not duplicate');
            $this->assertSame('greet', $files[0]->name);
            $this->assertSame($variant, $files[0]->path, 'path should point at the substitute');
            $this->assertSame(hash('sha256', 'test-variant'), $files[0]->hash);
        } finally {
            @unlink($variant);
            @rmdir($variantDir);
        }
    }

    public function testOverrideAddsBrandNewFileWhenNameMissing(): void
    {
        $variantDir = $this->tmpDir . '-variants';
        mkdir($variantDir);
        $variant = $variantDir . '/new.aiml';
        file_put_contents($variant, 'fresh');

        try {
            $bot = new BotConfig('mybot', $this->tmpDir);
            $files = (new FileScanner())->scan($bot, ['new' => $variant]);

            $this->assertCount(1, $files);
            $this->assertSame('new', $files[0]->name);
        } finally {
            @unlink($variant);
            @rmdir($variantDir);
        }
    }

    public function testOverrideTargetMissingThrows(): void
    {
        $bot = new BotConfig('mybot', $this->tmpDir);
        $this->expectException(\KnLab\PbMigrate\Exception\ConfigException::class);
        (new FileScanner())->scan($bot, ['greet' => '/no/such/file.aiml']);
    }
}
