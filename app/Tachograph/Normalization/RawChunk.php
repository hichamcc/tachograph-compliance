<?php

namespace App\Tachograph\Normalization;

/**
 * One decoded Mapon driver/daily_activities response and the stored payload it came from.
 */
final readonly class RawChunk
{
    public function __construct(
        public array $payload,
        public ?int $rawPayloadId = null,
    ) {}
}
