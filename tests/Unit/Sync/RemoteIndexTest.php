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

    public function testFromResponseNormalisesPropertiesAndPdefaultsToEmptyName(): void
    {
        // The API listing reports these kinds with the kind name as the row
        // label, but local-side representation has no name component. The
        // index must normalise so `has()` matches bare-name local entries.
        $response = json_decode('{"files":[],"sets":[],"maps":[],"substitutions":[],"pdefaults":[{"name":"pdefaults"}],"properties":[{"name":"properties"}]}');
        \assert($response instanceof \stdClass);

        $index = RemoteIndex::fromResponse($response);
        $this->assertTrue($index->has(FileKind::Pdefaults, ''));
        $this->assertTrue($index->has(FileKind::Properties, ''));
        $this->assertFalse($index->has(FileKind::Pdefaults, 'pdefaults'), 'name should be normalised away');

        $all = $index->all();
        $this->assertCount(2, $all);
        foreach ($all as $entry) {
            $this->assertSame('', $entry->name);
        }
    }

    public function testAllFlattensAcrossKinds(): void
    {
        $response = json_decode('{"files":[{"name":"a.aiml"}],"maps":[{"name":"b"}]}');
        \assert($response instanceof \stdClass);

        $all = RemoteIndex::fromResponse($response)->all();
        $this->assertCount(2, $all);
    }
}
