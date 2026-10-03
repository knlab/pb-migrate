<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Exception;

use KnLab\PbMigrate\Exception\ApiErrorMessage;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\Exception\ApiException;

/**
 * Covers ApiErrorMessage, the bridge between pb-php's status-only exception
 * messages (>= 2.1.4) and the server detail users need to see:
 *   - JSON body with "message" → appended to the exception message
 *   - body without a usable message (non-JSON, missing, empty, non-string) → message unchanged
 *   - non-ApiException throwables → message unchanged
 *   - console markup in the server message is escaped
 */
final class ApiErrorMessageTest extends TestCase
{
    public function testDescribeAppendsServerMessage(): void
    {
        $e = new ApiException('Pandorabots API returned HTTP 404 for GET https://api.example/bot/app/x', 404, '{"status":"error","message":"not found"}');

        $this->assertSame('not found', ApiErrorMessage::detail($e));
        $this->assertSame(
            'Pandorabots API returned HTTP 404 for GET https://api.example/bot/app/x — not found',
            ApiErrorMessage::describe($e),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesWithoutUsableMessage(): iterable
    {
        yield 'non-JSON body' => ['<html>Bad Gateway</html>'];
        yield 'empty body' => [''];
        yield 'JSON without message' => ['{"status":"error"}'];
        yield 'blank message' => ['{"status":"error","message":"   "}'];
        yield 'non-string message' => ['{"status":"error","message":{"code":1}}'];
        yield 'JSON array' => ['["not","an","object"]'];
    }

    /**
     * @dataProvider bodiesWithoutUsableMessage
     */
    public function testDescribeFallsBackToExceptionMessage(string $body): void
    {
        $e = new ApiException('Pandorabots API returned HTTP 502 for GET https://api.example/bot/app/x', 502, $body);

        $this->assertNull(ApiErrorMessage::detail($e));
        $this->assertSame($e->getMessage(), ApiErrorMessage::describe($e));
    }

    public function testNonApiExceptionIsLeftUntouched(): void
    {
        $e = new \RuntimeException('plain failure');

        $this->assertNull(ApiErrorMessage::detail($e));
        $this->assertSame('plain failure', ApiErrorMessage::describe($e));
    }

    public function testDescribeEscapesConsoleMarkupInServerMessage(): void
    {
        $e = new ApiException('Pandorabots API returned HTTP 400 for POST https://api.example/bot/app/x', 400, '{"message":"bad <error> tag"}');

        $this->assertSame('bad <error> tag', ApiErrorMessage::detail($e), 'detail() returns the raw text');
        $this->assertStringContainsString('bad \\<error\\> tag', ApiErrorMessage::describe($e), 'describe() escapes markup for console output');
    }
}
