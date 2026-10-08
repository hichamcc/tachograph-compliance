<?php

namespace App\Services\Mapon;

use RuntimeException;

/**
 * Sanitized Mapon error. Never carries the request URL, the API key or a previous
 * exception (Guzzle messages contain the full URL, including a query-string key).
 */
class MaponException extends RuntimeException
{
    public const CONNECTION_FAILED = -1;

    public const HTTP_ERROR = -2;

    public const INVALID_RESPONSE = -3;

    public const NOT_CONFIGURED = -4;

    // Global Mapon error codes we handle explicitly.
    public const KEY_NOT_FOUND = 1005;

    public const KEY_HAS_NO_UNITS = 1008;

    public const REQUEST_LIMIT_REACHED = 1011;

    public const NEEDS_UNLIMITED_KEY = 1013;

    public const NEEDS_PAID_ADDON = 1015;

    public function __construct(
        string $message,
        int $code,
        public readonly string $endpoint = '',
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, $code);
    }

    public function isRetryable(): bool
    {
        return $this->code === self::REQUEST_LIMIT_REACHED
            || $this->code === self::CONNECTION_FAILED
            || ($this->httpStatus !== null && $this->httpStatus >= 500);
    }
}
