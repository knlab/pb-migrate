<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Sync;

use KnLab\PbMigrate\Sync\FileChange;
use KnLab\PbMigrate\Sync\FileChangeSet;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\FileKind;

final class FileChangeSetTest extends TestCase
{
    private FileChangeSet $set;

    protected function setUp(): void
    {
        $this->set = new FileChangeSet([
            new FileChange(FileChange::ADD, FileKind::File, 'greet'),
            new FileChange(FileChange::UPDATE, FileKind::File, 'fallback'),
            new FileChange(FileChange::DELETE, FileKind::Set, 'colors'),
            new FileChange(FileChange::UPDATE, FileKind::Map, 'greet'),
        ]);
    }

    public function testFilterByBareNameMatchesAcrossKinds(): void
    {
        $filtered = $this->set->filter(['greet']);
        $this->assertCount(2, $filtered->all(), 'name "greet" matches both file/greet and map/greet');
    }

    public function testFilterByKindNameIsSpecific(): void
    {
        $filtered = $this->set->filter(['file/greet']);
        $this->assertCount(1, $filtered->all());
        $this->assertSame('greet', $filtered->all()[0]->name);
        $this->assertSame(FileKind::File, $filtered->all()[0]->kind);
    }

    public function testFilterAcceptsMultiplePatterns(): void
    {
        $filtered = $this->set->filter(['fallback', 'set/colors']);
        $this->assertCount(2, $filtered->all());
    }

    public function testFilterEmptyPatternsReturnsSameSet(): void
    {
        $filtered = $this->set->filter([]);
        $this->assertSame($this->set->all(), $filtered->all());
    }

    public function testFilterNoMatchReturnsEmpty(): void
    {
        $filtered = $this->set->filter(['nope']);
        $this->assertSame([], $filtered->all());
    }

    public function testWithChangesReplacesList(): void
    {
        $smaller = $this->set->withChanges([new FileChange(FileChange::ADD, FileKind::File, 'only-this')]);
        $this->assertCount(1, $smaller->all());
    }
}
