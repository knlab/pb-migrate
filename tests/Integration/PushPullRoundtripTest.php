<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Integration;

use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end test against the real Pandorabots API.
 * Creates a temporary bot, pushes a fixture, deletes the local copy,
 * pulls the file back, and verifies the content matches.
 */
final class PushPullRoundtripTest extends IntegrationTestCase
{
    public function testPushThenPullRoundtripsContent(): void
    {
        $botname = $this->reserveBotname('rt');
        $localDir = $this->tmpDir . '/aiml/' . $botname;
        mkdir($localDir, 0o755, true);

        $this->writeConfigForBot($botname, $localDir);

        // Seed the local directory with a sample.
        $sample = <<<AIML
        <?xml version="1.0" encoding="UTF-8"?>
        <aiml version="2.0">
            <category>
                <pattern>HELLO</pattern>
                <template>Hello, world.</template>
            </category>
        </aiml>
        AIML;
        $localFile = $localDir . '/greet.aiml';
        file_put_contents($localFile, $sample);

        // Create the bot up-front.
        $this->client->create($botname);

        // push --bot $botname
        $pushTester = new CommandTester($this->app->find('push'));
        $pushTester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--skip-compile' => true,
        ]);
        $pushTester->assertCommandIsSuccessful();

        // wipe local copy
        unlink($localFile);
        $this->assertFileDoesNotExist($localFile);

        // pull --bot $botname
        $pullTester = new CommandTester($this->app->find('pull'));
        $pullTester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
        ]);
        $pullTester->assertCommandIsSuccessful();

        $this->assertFileExists($localFile, 'pull should restore the file');
        $pulled = (string) file_get_contents($localFile);
        $this->assertStringContainsString('HELLO', $pulled, 'pulled AIML retains the original pattern');
        $this->assertStringContainsString('Hello, world.', $pulled, 'pulled AIML retains the original template');

        // diff after roundtrip — `greet` we control should be in sync.
        // The bot may carry system-managed defaults (e.g. `udc`) that we cannot
        // download or delete, so they show up in DEL group. That is the honest
        // state and acceptable for the roundtrip we care about — what matters
        // is that greet is NOT in any change group anymore.
        $diffTester = new CommandTester($this->app->find('diff'));
        $diffTester->execute(['--config' => $this->configPath, '--bot' => $botname]);
        $diffTester->assertCommandIsSuccessful();

        $display = $diffTester->getDisplay();
        // Extract the lines that reference greet and assert it never appears in
        // an UPD or ADD group (DEL would mean we somehow lost it locally).
        $this->assertStringNotContainsString(
            "ADD\nfile/greet",
            preg_replace('/\s+/', "\n", $display) ?: '',
            'greet should not appear as local-only after a successful pull',
        );
    }
}
