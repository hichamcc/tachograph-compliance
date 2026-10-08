<?php

namespace App\Tachograph\Data;

use DateTimeImmutable;

/**
 * Zero-length event (card inserted/removed, work period started/finished). Not an activity.
 */
final readonly class TachoEvent
{
    public function __construct(
        public string $driverId,
        public ?string $vehicleId,
        public TachoEventType $type,
        public DateTimeImmutable $occurredAt,
        public ActivitySource $source,
        public string $sourceEventId,
        public ?int $rawPayloadId = null,
    ) {}
}
