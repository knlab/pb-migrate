<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Repl;

use KnLab\PbMigrate\Repl\ReplLoop;
use PHPUnit\Framework\TestCase;

final class ReplLoopTest extends TestCase
{
    public function testNormaliseQuotesMultiWordTalkInputBeforeFlag(): void
    {
        $this->assertSame(
            'talk "what is your name" --bot mybot',
            ReplLoop::normaliseChatInput('talk what is your name --bot mybot'),
        );
    }

    public function testNormaliseQuotesMultiWordDebugInputAtEndOfLine(): void
    {
        $this->assertSame(
            'debug "tell me a joke"',
            ReplLoop::normaliseChatInput('debug tell me a joke'),
        );
    }

    public function testNormaliseQuotesMultiWordAtalkInput(): void
    {
        $this->assertSame(
            'atalk "i need help" --bot foo',
            ReplLoop::normaliseChatInput('atalk i need help --bot foo'),
        );
    }

    public function testNormaliseLeavesSingleWordUntouched(): void
    {
        $this->assertSame(
            'talk hello --bot mybot',
            ReplLoop::normaliseChatInput('talk hello --bot mybot'),
        );
    }

    public function testNormaliseLeavesAlreadyQuotedInputUntouched(): void
    {
        $original = 'talk "what is your name" --bot mybot';
        $this->assertSame($original, ReplLoop::normaliseChatInput($original));
    }

    public function testNormaliseLeavesNonChatCommandsUntouched(): void
    {
        $original = 'add ./aiml/foo --bot mybot';
        $this->assertSame($original, ReplLoop::normaliseChatInput($original));
    }

    public function testNormaliseDoesNotMatchUnrelatedPrefixes(): void
    {
        $original = 'talkabout something';
        $this->assertSame($original, ReplLoop::normaliseChatInput($original));
    }
}
