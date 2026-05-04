<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Config;

use KnLab\PbMigrate\Config\EnvFile;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    private string $tmpDir;
    private string $envPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-env-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->envPath = $this->tmpDir . '/.env';
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);
        @rmdir($this->tmpDir);
    }

    public function testReadBlockReturnsNullWhenFileMissing(): void
    {
        $f = new EnvFile($this->envPath);
        $this->assertNull($f->readBlock('app'));
    }

    public function testWriteAndReadBlock(): void
    {
        $f = new EnvFile($this->envPath);
        $f->writeBlock('app', ['PB_APP_ID' => 'app-123', 'PB_USER_KEY' => 'key-456']);

        $vars = $f->readBlock('app');
        $this->assertSame(['PB_APP_ID' => 'app-123', 'PB_USER_KEY' => 'key-456'], $vars);
    }

    public function testWriteBlockReplacesExisting(): void
    {
        $f = new EnvFile($this->envPath);
        $f->writeBlock('app', ['PB_APP_ID' => 'old-id', 'PB_USER_KEY' => 'old-key']);
        $f->writeBlock('app', ['PB_APP_ID' => 'new-id']);

        $vars = $f->readBlock('app');
        $this->assertSame(['PB_APP_ID' => 'new-id'], $vars);
    }

    public function testWriteBlockPreservesUserManagedLines(): void
    {
        // Pre-populate with user content + a managed block + more user content
        file_put_contents($this->envPath, <<<ENV
        # User comment
        MY_OWN_VAR=foo

        # pb-migrate:begin app
        PB_APP_ID=old
        # pb-migrate:end app

        OTHER_USER_VAR=bar
        ENV);

        $f = new EnvFile($this->envPath);
        $f->writeBlock('app', ['PB_APP_ID' => 'updated']);

        $contents = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('MY_OWN_VAR=foo', $contents);
        $this->assertStringContainsString('OTHER_USER_VAR=bar', $contents);
        $this->assertStringContainsString('PB_APP_ID=updated', $contents);
        $this->assertStringNotContainsString('PB_APP_ID=old', $contents);
    }

    public function testRemoveBlock(): void
    {
        $f = new EnvFile($this->envPath);
        $f->writeBlock('app', ['PB_APP_ID' => 'app']);
        $f->writeBlock('bot=mybot', ['PB_BOT_MYBOT_KEY' => 'secret']);

        $this->assertTrue($f->removeBlock('bot=mybot'));
        $this->assertNull($f->readBlock('bot=mybot'));
        $this->assertNotNull($f->readBlock('app'), 'unrelated block must remain');
    }

    public function testRemoveBlockReturnsFalseWhenAbsent(): void
    {
        $f = new EnvFile($this->envPath);
        $this->assertFalse($f->removeBlock('app'));
    }

    public function testListBlocks(): void
    {
        $f = new EnvFile($this->envPath);
        $f->writeBlock('app', ['PB_APP_ID' => 'a']);
        $f->writeBlock('bot=greeter', ['PB_BOT_GREETER_KEY' => 'k1']);
        $f->writeBlock('bot=support', ['PB_BOT_SUPPORT_KEY' => 'k2']);

        $this->assertSame(['app', 'bot=greeter', 'bot=support'], $f->listBlocks());
    }

    public function testQuotesValuesContainingWhitespaceOrSpecialChars(): void
    {
        $f = new EnvFile($this->envPath);
        $f->writeBlock('app', ['MY_VAR' => 'value with spaces']);

        $contents = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('MY_VAR="value with spaces"', $contents);

        // Round-trip
        $vars = $f->readBlock('app');
        $this->assertSame(['MY_VAR' => 'value with spaces'], $vars);
    }

    public function testApplyToProcessSetsEnvVars(): void
    {
        EnvFile::applyToProcess(['PBM_TEST_VAR_X' => 'set-via-applyToProcess']);
        $this->assertSame('set-via-applyToProcess', getenv('PBM_TEST_VAR_X'));
        putenv('PBM_TEST_VAR_X');
    }

    public function testBlockIdAndEnvNameHelpers(): void
    {
        $this->assertSame('bot=greeter', EnvFile::blockIdForBot('greeter'));
        $this->assertSame('PB_BOT_GREETER_KEY', EnvFile::envNameForBotKey('greeter'));
        $this->assertSame('PB_BOT_PRODBOT_KEY', EnvFile::envNameForBotKey('prodbot'));
    }
}
