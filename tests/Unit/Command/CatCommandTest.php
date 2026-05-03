<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KnLab\PbMigrate\Application;
use KnLab\PbMigrate\Exception\ConfigException;
use KnLab\PbMigrate\PBClientFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CatCommandTest extends TestCase
{
    private string $tmpDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-cat-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->configPath = $this->tmpDir . '/pb-migrate.json';

        putenv('PB_APP_ID=app');
        putenv('PB_USER_KEY=key');
        file_put_contents($this->configPath, json_encode([
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => ['mybot' => ['directory' => './aiml/mybot']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @rmdir($this->tmpDir);
        putenv('PB_APP_ID');
        putenv('PB_USER_KEY');
    }

    private function tester(MockHandler $mock): CommandTester
    {
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $app = new Application('pb-migrate', '0.4.0', new PBClientFactory($http));
        return new CommandTester($app->find('cat'));
    }

    public function testCatPrintsRawBodyForFileKind(): void
    {
        $aiml = "<aiml><category><pattern>HI</pattern><template>hi</template></category></aiml>";
        $mock = new MockHandler([new Response(200, [], $aiml)]);
        $tester = $this->tester($mock);

        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--kind' => 'file',
            'name' => 'greet',
        ]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('HI', $tester->getDisplay());
    }

    public function testCatRequiresNameForKindWithFilename(): void
    {
        $tester = $this->tester(new MockHandler());
        $this->expectException(ConfigException::class);
        $tester->execute(['--config' => $this->configPath, '--bot' => 'mybot', '--kind' => 'file']);
    }

    public function testCatForbidsNameForPropertiesKind(): void
    {
        $tester = $this->tester(new MockHandler());
        $this->expectException(ConfigException::class);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--kind' => 'properties',
            'name' => 'oops',
        ]);
    }

    public function testCatRejectsUnknownKind(): void
    {
        $tester = $this->tester(new MockHandler());
        $this->expectException(ConfigException::class);
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--kind' => 'bogus',
            'name' => 'greet',
        ]);
    }

    public function testCatPrintsPropertiesWithoutName(): void
    {
        $body = "key1=value1\nkey2=value2\n";
        $mock = new MockHandler([new Response(200, [], $body)]);
        $tester = $this->tester($mock);

        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => 'mybot',
            '--kind' => 'properties',
        ]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('key1=value1', $tester->getDisplay());
    }
}
