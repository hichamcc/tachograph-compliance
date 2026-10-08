<?php

namespace App\Tachograph\Data;

use DateTimeImmutable;

final readonly class Activity
{
    /**
     * @param  list<string>  $sourceEventIds  keys of the normalized records this activity was built from (evidence)
     */
    public function __construct(
        public string $driverId,
        public ?string $vehicleId,
        public ActivityType $type,
        public DateTimeImmutable $start,   // UTC
        public DateTimeImmutable $end,     // UTC
        public ActivitySource $source = ActivitySource::LOCAL,
        public bool $uncertain = false,
        public array $sourceEventIds = [],
        public ?string $rawStatus = null,
        public ?int $rawPayloadId = null,
    ) {}

    /** Stable dedupe key: sha1(driver|start|end|status|vehicle). */
    public static function key(string $driverId, int $start, int $end, string $status, ?string $vehicleId): string
    {
        return sha1(implode('|', [$driverId, $start, $end, $status, $vehicleId ?? '']));
    }

    public function durationSeconds(): int
    {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }

    public function period(): Period
    {
        return new Period($this->start, $this->end);
    }

    public function withType(ActivityType $type): self
    {
        return $this->with(['type' => $type]);
    }

    public function withTimes(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return $this->with(['start' => $start, 'end' => $end]);
    }

    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** The part of this activity inside the period, or null when they don't overlap. */
    public function clip(Period $period): ?self
    {
        $start = max($this->start, $period->start);
        $end = min($this->end, $period->end);

        return $start < $end ? $this->withTimes($start, $end) : null;
    }
}
