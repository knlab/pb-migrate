<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Integration;

use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for bot:files, cat, and file:delete commands.
 *
 * Run with:
 *   PB_APP_ID=xxx PB_USER_KEY=yyy composer test:integration
 */
final class BotFilesCommandsTest extends IntegrationTestCase
{
    public function testBotFilesListsUploadedAiml(): void
    {
        $botname = $this->reserveBotname('bfc');
        $localDir = $this->tmpDir . '/aiml/' . $botname;
        mkdir($localDir, 0o755, true);

        $this->writeConfigForBot($botname, $localDir);
        file_put_contents($localDir . '/greet.aiml', $this->sampleAiml());
        $this->client->create($botname);

        $pushTester = new CommandTester($this->app->find('push'));
        $pushTester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--skip-compile' => true,
        ]);
        $pushTester->assertCommandIsSuccessful();

        $tester = new CommandTester($this->app->find('bot:files'));
        $tester->execute(['--config' => $this->configPath, '--bot' => $botname]);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('AIML', $display, 'bot:files should show the AIML section');
        $this->assertStringContainsString('greet', $display, 'bot:files should list the uploaded greet file');
    }

    public function testCatFetchesAimlContent(): void
    {
        $botname = $this->reserveBotname('cat');
        $localDir = $this->tmpDir . '/aiml/' . $botname;
        mkdir($localDir, 0o755, true);

        $this->writeConfigForBot($botname, $localDir);
        file_put_contents($localDir . '/greet.aiml', $this->sampleAiml());
        $this->client->create($botname);

        $pushTester = new CommandTester($this->app->find('push'));
        $pushTester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--skip-compile' => true,
        ]);
        $pushTester->assertCommandIsSuccessful();

        $tester = new CommandTester($this->app->find('cat'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--kind' => 'file',
            'name' => 'greet',
        ]);
        $tester->assertCommandIsSuccessful();

        $body = $tester->getDisplay(true);
        $this->assertStringContainsString('HELLO', $body, 'cat should return the AIML pattern');
        $this->assertStringContainsString('Hello, world.', $body, 'cat should return the AIML template');
    }

    public function testCatFetchesPropertiesContent(): void
    {
        $botname = $this->reserveBotname('catp');
        $localDir = $this->tmpDir . '/aiml/' . $botname;
        mkdir($localDir, 0o755, true);

        $this->writeConfigForBot($botname, $localDir);
        // Pandorabots expects properties in JSON [[key, value], ...] form.
        file_put_contents($localDir . '/bot.properties', (string) json_encode([
            ['botname', 'CatTest'],
            ['author', 'phpunit'],
        ]));
        $this->client->create($botname);

        $pushTester = new CommandTester($this->app->find('push'));
        $pushTester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--skip-compile' => true,
        ]);
        $pushTester->assertCommandIsSuccessful();

        $tester = new CommandTester($this->app->find('cat'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--kind' => 'properties',
        ]);
        $tester->assertCommandIsSuccessful();

        $body = $tester->getDisplay(true);
        $this->assertStringContainsString('CatTest', $body, 'cat should return the properties content');
    }

    public function testFileDeleteRemovesAimlFile(): void
    {
        $botname = $this->reserveBotname('del');
        $localDir = $this->tmpDir . '/aiml/' . $botname;
        mkdir($localDir, 0o755, true);

        $this->writeConfigForBot($botname, $localDir);
        file_put_contents($localDir . '/greet.aiml', $this->sampleAiml());
        $this->client->create($botname);

        $pushTester = new CommandTester($this->app->find('push'));
        $pushTester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--skip-compile' => true,
        ]);
        $pushTester->assertCommandIsSuccessful();

        $tester = new CommandTester($this->app->find('file:delete'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--kind' => 'file',
            '--yes' => true,
            'name' => 'greet',
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Deleted', $tester->getDisplay());

        // Verify the file is gone from the remote listing.
        $files = $this->client->getBotFiles($botname);
        $aimlNames = array_map(static fn (\stdClass $f) => $f->name, $files->files ?? []);
        $this->assertEmpty(
            array_intersect(['greet', 'greet.aiml'], $aimlNames),
            'greet should no longer appear in bot:files after file:delete',
        );
    }

    public function testFileDeleteRemovesPropertiesFile(): void
    {
        $botname = $this->reserveBotname('delp');
        $localDir = $this->tmpDir . '/aiml/' . $botname;
        mkdir($localDir, 0o755, true);

        $this->writeConfigForBot($botname, $localDir);
        // Pandorabots expects properties in JSON [[key, value], ...] form.
        file_put_contents($localDir . '/bot.properties', (string) json_encode([
            ['botname', 'DeleteTest'],
            ['author', 'phpunit'],
        ]));
        $this->client->create($botname);

        $pushTester = new CommandTester($this->app->find('push'));
        $pushTester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--skip-compile' => true,
        ]);
        $pushTester->assertCommandIsSuccessful();

        $tester = new CommandTester($this->app->find('file:delete'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--kind' => 'properties',
            '--yes' => true,
        ]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Deleted', $tester->getDisplay());
    }

    private function sampleAiml(): string
    {
        return <<<AIML
        <?xml version="1.0" encoding="UTF-8"?>
        <aiml version="2.0">
            <category>
                <pattern>HELLO</pattern>
                <template>Hello, world.</template>
            </category>
        </aiml>
        AIML;
    }
}
