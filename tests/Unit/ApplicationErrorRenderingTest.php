<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit;

use KnLab\PbMigrate\Application;
use PHPUnit\Framework\TestCase;
use Spontena\PbPhp\Exception\ApiException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Covers Application::doRenderThrowable, the path a direct (non-REPL) CLI
 * run takes when an ApiException escapes a command:
 *   - the server message from the response body is shown after the error block
 *   - exceptions without server detail render exactly as Symfony does
 */
final class ApplicationErrorRenderingTest extends TestCase
{
    public function testApiExceptionRenderingIncludesServerMessage(): void
    {
        $app = new Application('pb-migrate', '0.1.0');
        $output = new BufferedOutput();

        $app->renderThrowable(
            new ApiException('Pandorabots API returned HTTP 404 for GET https://api.example/bot/app/x', 404, '{"status":"error","message":"bot not found"}'),
            $output,
        );

        $display = $output->fetch();
        $this->assertStringContainsString('Pandorabots API returned HTTP 404', $display);
        $this->assertStringContainsString('Server message: bot not found', $display);
    }

    public function testExceptionWithoutServerDetailRendersWithoutExtraLine(): void
    {
        $app = new Application('pb-migrate', '0.1.0');
        $output = new BufferedOutput();

        $app->renderThrowable(new \RuntimeException('plain failure'), $output);

        $display = $output->fetch();
        $this->assertStringContainsString('plain failure', $display);
        $this->assertStringNotContainsString('Server message:', $display);
    }
}
