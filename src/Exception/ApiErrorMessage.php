<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Exception;

use Spontena\PbPhp\Exception\ApiException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Builds user-facing text for API failures.
 *
 * Since pb-php 2.1.4, ApiException::getMessage() carries only the HTTP status
 * and the request path; the server's own explanation lives in the response
 * body and is exposed via getDecodedBody(). This helper re-attaches that
 * explanation for display without parsing exception messages.
 */
final class ApiErrorMessage
{
    /**
     * The server-supplied error message (the "message" field of the JSON
     * error body), or null when the throwable is not an ApiException or the
     * body carries no usable message.
     */
    public static function detail(\Throwable $e): ?string
    {
        if (!$e instanceof ApiException) {
            return null;
        }

        $body = $e->getDecodedBody();
        if ($body === null || !isset($body->message) || !is_string($body->message)) {
            return null;
        }

        $message = trim($body->message);
        return $message === '' ? null : $message;
    }

    /**
     * getMessage() plus the server detail when one is available, formatted
     * for console output.
     */
    public static function describe(\Throwable $e): string
    {
        $detail = self::detail($e);
        if ($detail === null) {
            return $e->getMessage();
        }

        return sprintf('%s — %s', $e->getMessage(), OutputFormatter::escape($detail));
    }
}
