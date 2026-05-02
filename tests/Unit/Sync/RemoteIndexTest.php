<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Sync;

use KnLab\PbMigrate\Sync\RemoteIndex;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\FileKind;

final class RemoteIndexTest extends TestCase
{
    public function testFromResponseStripsAimlExtension(): void
    {
        $response = json_decode('{"files":[{"name":"greet.aiml"},{"name":"farewell"}],"sets":[],"maps":[],"substitutions":[],"pdefaults":[],"properties":[]}');
        \assert($response instanceof \stdClass);

        $index = RemoteIndex::fromResponse($response);

        $this->assertTrue($index->has(FileKind::File, 'greet'), '.aiml suffix should be stripped');
        $this->assertTrue($index->has(FileKind::File, 'farewell'));
        $this->assertFalse($index->has(FileKind::Set, 'greet'));
    }

    public function testFromResponseHandlesMissingKindKeys(): void
    {
        $response = json_decode('{"files":[{"name":"x.aiml"}]}');
        \assert($response instanceof \stdClass);

        $index = RemoteIndex::fromResponse($response);
        $this->assertTrue($index->has(FileKind::File, 'x'));
        $this->assertFalse($index->has(FileKind::Map, 'x'));
    }

    public function testAllFlattensAcrossKinds(): void
    {
        $response = json_decode('{"files":[{"name":"a.aiml"}],"maps":[{"name":"b"}]}');
        \assert($response instanceof \stdClass);

        $all = RemoteIndex::fromResponse($response)->all();
        $this->assertCount(2, $all);
    }
}
