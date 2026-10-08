<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Strips the Mapon API key and any "key=" query values from log messages and context.
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: self::redact($record->message),
                context: self::redactArray($record->context),
            );
        });
    }

    public static function redact(string $text): string
    {
        $key = (string) config('services.mapon.key');

        if ($key !== '') {
            $text = str_replace([$key, rawurlencode($key)], '[REDACTED]', $text);
        }

        return preg_replace('/([?&]key=)[^&\s"\']+/i', '$1[REDACTED]', $text);
    }

    private static function redactArray(array $context): array
    {
        array_walk_recursive($context, function (&$value) {
            if (is_string($value)) {
                $value = self::redact($value);
            }
        });

        return $context;
    }
}
