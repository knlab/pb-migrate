<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Sync;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KnLab\PbMigrate\Sync\DiffEngine;
use KnLab\PbMigrate\Sync\FileChange;
use KnLab\PbMigrate\Sync\LocalFile;
use KnLab\PbMigrate\Sync\RemoteIndex;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\FileKind;
use Spontena\PbPhp\PBClient;

final class DiffEngineTest extends TestCase
{
    private MockHandler $mock;
    private PBClient $client;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $http = new Client(['handler' => HandlerStack::create($this->mock)]);
        $this->client = new PBClient(
            host: 'https://api.pandorabots.com',
            appId: 'app',
            userKey: 'key',
            http: $http,
        );
    }

    private function index(string $json): RemoteIndex
    {
        $decoded = json_decode($json);
        \assert($decoded instanceof \stdClass);
        return RemoteIndex::fromResponse($decoded);
    }

    public function testAddOnlyWhenLocalHasFileMissingOnRemote(): void
    {
        $local = [new LocalFile('/tmp/x.aiml', 'x', FileKind::File, sha1('hello'))];
        $remote = $this->index('{"files":[]}');

        $changes = (new DiffEngine())->compute($this->client, 'mybot', $local, $remote);

        $this->assertCount(1, $changes->all());
        $this->assertSame(FileChange::ADD, $changes->all()[0]->action);
    }

    public function testDeleteOnlyWhenRemoteHasFileMissingLocally(): void
    {
        $local = [];
        $remote = $this->index('{"files":[{"name":"x.aiml"}]}');

        $changes = (new DiffEngine())->compute($this->client, 'mybot', $local, $remote);

        $this->assertCount(1, $changes->all());
        $this->assertSame(FileChange::DELETE, $changes->all()[0]->action);
        $this->assertSame('x', $changes->all()[0]->name);
    }

    public function testUpdateWhenContentDiffersByHash(): void
    {
        $local = [new LocalFile('/tmp/x.aiml', 'x', FileKind::File, sha1('local'))];
        $remote = $this->index('{"files":[{"name":"x.aiml"}]}');

        // Remote fetch returns different content → update.
        $this->mock->append(new Response(200, [], 'remote-content'));

        $changes = (new DiffEngine())->compute($this->client, 'mybot', $local, $remote);

        $this->assertCount(1, $changes->all());
        $this->assertSame(FileChange::UPDATE, $changes->all()[0]->action);
    }

    public function testNoChangeWhenContentMatches(): void
    {
        $local = [new LocalFile('/tmp/x.aiml', 'x', FileKind::File, sha1('same'))];
        $remote = $this->index('{"files":[{"name":"x.aiml"}]}');

        $this->mock->append(new Response(200, [], 'same'));

        $changes = (new DiffEngine())->compute($this->client, 'mybot', $local, $remote);

        $this->assertSame([], $changes->all());
    }
}
