<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Repl;

use KnLab\PbMigrate\Repl\RunbookParser;
use PHPUnit\Framework\TestCase;

final class RunbookParserTest extends TestCase
{
    public function testParseStringSkipsBlanksAndComments(): void
    {
        $contents = <<<RUNBOOK
        # Weekly cleanup
        # Run every Monday

        bot:list

        # 1. Pull anything that drifted
        pull --bot greeter
            # indented comment
        pull --bot support

        push --all
        RUNBOOK;

        $commands = RunbookParser::parseString($contents);

        $this->assertSame([
            'bot:list',
            'pull --bot greeter',
            'pull --bot support',
            'push --all',
        ], $commands);
    }

    public function testParseStringTrimsTrailingWhitespace(): void
    {
        $commands = RunbookParser::parseString("bot:list   \n  push --all  \n");
        $this->assertSame(['bot:list', 'push --all'], $commands);
    }

    public function testParseFileFailsForMissingPath(): void
    {
        $this->expectException(\RuntimeException::class);
        RunbookParser::parseFile('/no/such/file.txt');
    }

    public function testParseFileReadsFromDisk(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pbm-rb');
        file_put_contents($tmp, "# header\nbot:list\n");
        try {
            $this->assertSame(['bot:list'], RunbookParser::parseFile($tmp));
        } finally {
            @unlink($tmp);
        }
    }
}
