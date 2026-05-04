<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Integration;

use Spontena\PbPhp\FileKind;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration test for the alter:* commands and their interaction with push.
 *
 * Workflow exercised end-to-end:
 *   1. set an alter pointing greet → variants/greet-debug.aiml
 *   2. push  — bot now serves the variant body
 *   3. unset the alter
 *   4. push  — bot reverts to the canonical body
 *
 * Run with:
 *   PB_APP_ID=xxx PB_USER_KEY=yyy composer test:integration
 */
final class AlterPersistenceTest extends IntegrationTestCase
{
    public function testAlterSetPushUnsetPushRoundtripsBetweenVariantAndCanonical(): void
    {
        $botname = $this->reserveBotname('alt');
        $localDir = $this->tmpDir . '/aiml/' . $botname;
        $variantsDir = $this->tmpDir . '/variants';
        mkdir($localDir, 0o755, true);
        mkdir($variantsDir, 0o755, true);

        $canonical = $this->aimlWithTemplate('canonical-greet');
        $variant = $this->aimlWithTemplate('debug-greet-probe');
        file_put_contents($localDir . '/greet.aiml', $canonical);
        file_put_contents($variantsDir . '/greet-debug.aiml', $variant);

        $this->writeConfigForBot($botname, $localDir);
        $this->client->create($botname);

        // 1. alter:set greet variants/greet-debug.aiml
        $alterSetTester = new CommandTester($this->app->find('alter:set'));
        $alterSetTester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            'name' => 'greet',
            'path' => 'variants/greet-debug.aiml',
        ]);
        $alterSetTester->assertCommandIsSuccessful();

        // 2. push — variant should land remotely
        $this->push($botname);
        $afterAlter = $this->client->getBotFile(FileKind::File, $botname, 'greet');
        $this->assertStringContainsString('debug-greet-probe', $afterAlter, 'variant body should be uploaded by push when alter is active');
        $this->assertStringNotContainsString('canonical-greet', $afterAlter, 'canonical body must not be present when alter is active');

        // 3. alter:unset greet
        $alterUnsetTester = new CommandTester($this->app->find('alter:unset'));
        $alterUnsetTester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            'name' => 'greet',
        ]);
        $alterUnsetTester->assertCommandIsSuccessful();

        // 4. push — canonical should land remotely again
        $this->push($botname);
        $afterUnset = $this->client->getBotFile(FileKind::File, $botname, 'greet');
        $this->assertStringContainsString('canonical-greet', $afterUnset, 'canonical body should be restored by push after alter:unset');
        $this->assertStringNotContainsString('debug-greet-probe', $afterUnset, 'variant body must be cleared after alter:unset');
    }

    public function testAlterListReflectsPersistedConfig(): void
    {
        $botname = $this->reserveBotname('aulist');
        $localDir = $this->tmpDir . '/aiml/' . $botname;
        $variantsDir = $this->tmpDir . '/variants';
        mkdir($localDir, 0o755, true);
        mkdir($variantsDir, 0o755, true);
        file_put_contents($variantsDir . '/dump.aiml', $this->aimlWithTemplate('dump'));

        $this->writeConfigForBot($botname, $localDir);
        // No bot creation needed — alter:list does not call the API.

        $setter = new CommandTester($this->app->find('alter:set'));
        $setter->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            'name' => '_dump',
            'path' => 'variants/dump.aiml',
        ]);
        $setter->assertCommandIsSuccessful();

        $lister = new CommandTester($this->app->find('alter:list'));
        $lister->execute(['--config' => $this->configPath, '--bot' => $botname]);
        $lister->assertCommandIsSuccessful();

        $display = $lister->getDisplay();
        $this->assertStringContainsString('_dump', $display);
        $this->assertStringContainsString('variants/dump.aiml', $display);
    }

    private function push(string $botname): void
    {
        $tester = new CommandTester($this->app->find('push'));
        $tester->execute([
            '--config' => $this->configPath,
            '--bot' => $botname,
            '--skip-compile' => true,
        ]);
        $tester->assertCommandIsSuccessful();
    }

    private function aimlWithTemplate(string $marker): string
    {
        return <<<AIML
        <?xml version="1.0" encoding="UTF-8"?>
        <aiml version="2.0">
            <category>
                <pattern>HELLO</pattern>
                <template>{$marker}</template>
            </category>
        </aiml>
        AIML;
    }
}
