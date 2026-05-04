<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Sync;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KnLab\PbMigrate\Sync\CacheStore;
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
        $local = [new LocalFile('/tmp/x.aiml', 'x', FileKind::File, hash('sha256', 'hello'))];
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
        $local = [new LocalFile('/tmp/x.aiml', 'x', FileKind::File, hash('sha256', 'local'))];
        $remote = $this->index('{"files":[{"name":"x.aiml"}]}');
        $this->mock->append(new Response(200, [], 'remote-content'));

        $changes = (new DiffEngine())->compute($this->client, 'mybot', $local, $remote);

        $this->assertCount(1, $changes->all());
        $this->assertSame(FileChange::UPDATE, $changes->all()[0]->action);
    }

    public function testNoChangeWhenContentMatches(): void
    {
        $local = [new LocalFile('/tmp/x.aiml', 'x', FileKind::File, hash('sha256', 'same'))];
        $remote = $this->index('{"files":[{"name":"x.aiml"}]}');
        $this->mock->append(new Response(200, [], 'same'));

        $changes = (new DiffEngine())->compute($this->client, 'mybot', $local, $remote);

        $this->assertSame([], $changes->all());
    }

    public function testCacheHitSkipsRemoteFetchWhenLocalHashMatches(): void
    {
        $localHash = hash('sha256', 'cached-body');
        $local = [new LocalFile('/tmp/x.aiml', 'x', FileKind::File, $localHash)];
        $remote = $this->index('{"files":[{"name":"x.aiml"}]}');

        $cache = new CacheStore(sys_get_temp_dir() . '/non-existent-cache.json');
        $cache->set('mybot', FileKind::File, 'x', $localHash);

        // Don't append a Response — if DiffEngine calls getBotFile, MockHandler
        // would throw an OutOfBoundsException. So this test verifies *no*
        // network call is made.
        $changes = (new DiffEngine())->compute($this->client, 'mybot', $local, $remote, $cache);

        $this->assertSame([], $changes->all());
    }

    public function testCacheMissingForcesUpdateWithoutFetch(): void
    {
        $local = [new LocalFile('/tmp/x.aiml', 'x', FileKind::File, hash('sha256', 'changed'))];
        $remote = $this->index('{"files":[{"name":"x.aiml"}]}');

        $cache = new CacheStore(sys_get_temp_dir() . '/non-existent-cache.json');
        $cache->set('mybot', FileKind::File, 'x', hash('sha256', 'old-version'));

        $changes = (new DiffEngine())->compute($this->client, 'mybot', $local, $remote, $cache);

        $this->assertCount(1, $changes->all());
        $this->assertSame(FileChange::UPDATE, $changes->all()[0]->action);
    }

    public function testSystemManagedUdcIsNeverDeleted(): void
    {
        // Pandorabots auto-creates `udc` on bot:create and rejects DELETE with
        // 412. Filtering at planning time stops every push from showing a
        // phantom DEL line and a misleading 412 warning.
        $local = [];
        $remote = $this->index('{"files":[{"name":"udc.aiml"},{"name":"greet.aiml"}]}');

        $changes = (new DiffEngine())->compute($this->client, 'mybot', $local, $remote);

        $names = array_map(static fn ($c) => $c->name, $changes->all());
        $this->assertContains('greet', $names);
        $this->assertNotContains('udc', $names, 'system-managed file must be filtered from delete plan');
    }

    public function testPropertiesJsonComparisonIgnoresPandorabotsReformatting(): void
    {
        // Pandorabots stores properties/pdefaults pretty-printed across multiple
        // lines. A raw byte hash would always disagree with the user's
        // single-line local file even when the JSON is semantically identical.
        $tmpFile = sys_get_temp_dir() . '/pbm-diff-' . bin2hex(random_bytes(4));
        file_put_contents($tmpFile, '[["botname","Persona"],["language","en"]]');

        try {
            $localHash = hash_file('sha256', $tmpFile) ?: '';
            $local = [new LocalFile($tmpFile, '', FileKind::Properties, $localHash)];
            $remote = $this->index('{"properties":[{"name":"properties"}]}');

            $remotePretty = "[\n[\"botname\",\"Persona\"],\n[\"language\",\"en\"]\n]";
            $this->mock->append(new Response(200, [], $remotePretty));

            $changes = (new DiffEngine())->compute(
                client: $this->client,
                botname: 'mybot',
                localFiles: $local,
                remote: $remote,
                fullCheck: true,
            );

            $this->assertSame([], $changes->all(), 'semantically identical JSON must not be reported as drift');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testJsonComparisonHandlesPandorabotsReordering(): void
    {
        // The server reorders entries on storage (observed: properties uploaded
        // as [botname, author, version, language] comes back as
        // [language, version, author, botname]). Canonicalisation must sort
        // entries so reordering doesn't register as drift.
        $tmpFile = sys_get_temp_dir() . '/pbm-diff-' . bin2hex(random_bytes(4));
        file_put_contents($tmpFile, '[["botname","Persona"],["author","dogfood"],["version","1.0"],["language","en"]]');

        try {
            $localHash = hash_file('sha256', $tmpFile) ?: '';
            $local = [new LocalFile($tmpFile, '', FileKind::Properties, $localHash)];
            $remote = $this->index('{"properties":[{"name":"properties"}]}');

            $remoteReordered = "[\n[\"language\",\"en\"],\n[\"version\",\"1.0\"],\n[\"author\",\"dogfood\"],\n[\"botname\",\"Persona\"]\n]";
            $this->mock->append(new Response(200, [], $remoteReordered));

            $changes = (new DiffEngine())->compute(
                client: $this->client,
                botname: 'mybot',
                localFiles: $local,
                remote: $remote,
                fullCheck: true,
            );

            $this->assertSame([], $changes->all(), 'reordered entries are still semantically the same');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testJsonComparisonAppliesToSetMapSubstitution(): void
    {
        // Set / Map / Substitution are also JSON arrays that the server
        // reformats; the canonicalisation must cover them too.
        $tmpFile = sys_get_temp_dir() . '/pbm-diff-' . bin2hex(random_bytes(4));
        file_put_contents($tmpFile, '[["red"],["green"],["blue"]]');

        try {
            $localHash = hash_file('sha256', $tmpFile) ?: '';
            $local = [new LocalFile($tmpFile, 'colors', FileKind::Set, $localHash)];
            $remote = $this->index('{"sets":[{"name":"colors"}]}');

            $remoteReordered = "[\n[\"blue\"],\n[\"green\"],\n[\"red\"]\n]";
            $this->mock->append(new Response(200, [], $remoteReordered));

            $changes = (new DiffEngine())->compute(
                client: $this->client,
                botname: 'mybot',
                localFiles: $local,
                remote: $remote,
                fullCheck: true,
            );

            $this->assertSame([], $changes->all());
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testPropertiesJsonComparisonStillDetectsRealDrift(): void
    {
        $tmpFile = sys_get_temp_dir() . '/pbm-diff-' . bin2hex(random_bytes(4));
        file_put_contents($tmpFile, '[["botname","Persona"]]');

        try {
            $localHash = hash_file('sha256', $tmpFile) ?: '';
            $local = [new LocalFile($tmpFile, '', FileKind::Properties, $localHash)];
            $remote = $this->index('{"properties":[{"name":"properties"}]}');

            $this->mock->append(new Response(200, [], '[["botname","Different"]]'));

            $changes = (new DiffEngine())->compute(
                client: $this->client,
                botname: 'mybot',
                localFiles: $local,
                remote: $remote,
                fullCheck: true,
            );

            $this->assertCount(1, $changes->all());
            $this->assertSame(FileChange::UPDATE, $changes->all()[0]->action);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testFullCheckBypassesCache(): void
    {
        $local = [new LocalFile('/tmp/x.aiml', 'x', FileKind::File, hash('sha256', 'same'))];
        $remote = $this->index('{"files":[{"name":"x.aiml"}]}');

        $cache = new CacheStore(sys_get_temp_dir() . '/non-existent-cache.json');
        $cache->set('mybot', FileKind::File, 'x', hash('sha256', 'mismatched-stale-cache'));

        // With fullCheck=true, the remote body should be fetched and compared.
        $this->mock->append(new Response(200, [], 'same'));

        $changes = (new DiffEngine())->compute(
            client: $this->client,
            botname: 'mybot',
            localFiles: $local,
            remote: $remote,
            cache: $cache,
            fullCheck: true,
        );

        $this->assertSame([], $changes->all(), 'remote actually matches; no change despite stale cache');
    }
}
