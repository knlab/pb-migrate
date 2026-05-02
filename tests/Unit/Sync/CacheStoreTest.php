<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Sync;

use KnLab\PbMigrate\Sync\CacheStore;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\FileKind;

final class CacheStoreTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/pbm-cache-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    public function testRoundtripSetGetSave(): void
    {
        $cache = new CacheStore($this->tmpFile);
        $cache->set('mybot', FileKind::File, 'greet', 'abc');
        $cache->set('mybot', FileKind::Set, 'colors', 'def');
        $cache->set('mybot', FileKind::Properties, '', '111');
        $cache->save();

        $this->assertFileExists($this->tmpFile);

        $cache2 = new CacheStore($this->tmpFile);
        $cache2->load();
        $this->assertSame('abc', $cache2->get('mybot', FileKind::File, 'greet'));
        $this->assertSame('def', $cache2->get('mybot', FileKind::Set, 'colors'));
        $this->assertSame('111', $cache2->get('mybot', FileKind::Properties, ''));
    }

    public function testGetReturnsNullForUnknownEntry(): void
    {
        $cache = new CacheStore($this->tmpFile);
        $this->assertNull($cache->get('mybot', FileKind::File, 'nope'));
    }

    public function testForgetRemovesEntry(): void
    {
        $cache = new CacheStore($this->tmpFile);
        $cache->set('mybot', FileKind::File, 'greet', 'abc');
        $cache->forget('mybot', FileKind::File, 'greet');
        $this->assertNull($cache->get('mybot', FileKind::File, 'greet'));
    }

    public function testSaveSkippedWhenNotDirty(): void
    {
        $cache = new CacheStore($this->tmpFile);
        $cache->save();
        $this->assertFileDoesNotExist($this->tmpFile);
    }

    public function testCorruptedFileIsIgnored(): void
    {
        file_put_contents($this->tmpFile, 'not json');
        $cache = new CacheStore($this->tmpFile);
        $cache->load();
        $this->assertNull($cache->get('mybot', FileKind::File, 'x'));
    }

    public function testMultipleBotsKeptIsolated(): void
    {
        $cache = new CacheStore($this->tmpFile);
        $cache->set('a', FileKind::File, 'shared', 'hash-a');
        $cache->set('b', FileKind::File, 'shared', 'hash-b');

        $this->assertSame('hash-a', $cache->get('a', FileKind::File, 'shared'));
        $this->assertSame('hash-b', $cache->get('b', FileKind::File, 'shared'));
    }
}
