<?php

namespace App\Tachograph\Data;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Half-open time interval [start, end) in UTC.
 */
final readonly class Period
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException('Period end is before its start.');
        }
    }

    public static function fromStrings(string $start, string $end): self
    {
        $utc = new DateTimeZone('UTC');

        return new self(
            (new DateTimeImmutable($start, $utc))->setTimezone($utc),
            (new DateTimeImmutable($end, $utc))->setTimezone($utc),
        );
    }

    public function durationSeconds(): int
    {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }

    public function contains(DateTimeImmutable $time): bool
    {
        return $time >= $this->start && $time < $this->end;
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function intersect(self $other): ?self
    {
        $start = max($this->start, $other->start);
        $end = min($this->end, $other->end);

        return $start < $end ? new self($start, $end) : null;
    }
}
