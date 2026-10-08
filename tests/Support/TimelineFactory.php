<?php

namespace Tests\Support;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivitySource;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use App\Tachograph\Data\Timeline;
use App\Tachograph\Normalization\TimelineBuilder;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Fluent builder for test timelines. All times are UTC.
 *
 *   TimelineFactory::driver('test_driver_01')
 *       ->startAt('2026-09-28 08:00')
 *       ->drive('4h30m')->rest('45m')->drive('4h')->work('30m')->rest('11h')
 *       ->build();
 */
final class TimelineFactory
{
    /** @var list<Activity> */
    private array $activities = [];

    private DateTimeImmutable $cursor;

    private function __construct(private readonly string $driverId)
    {
        $this->cursor = new DateTimeImmutable('2026-09-28 00:00', new DateTimeZone('UTC'));
    }

    public static function driver(string $driverId = 'test_driver_01'): self
    {
        return new self($driverId);
    }

    public function startAt(string $time): self
    {
        $this->cursor = new DateTimeImmutable($time, new DateTimeZone('UTC'));

        return $this;
    }

    public function drive(string $duration, ActivitySource $source = ActivitySource::LOCAL, bool $uncertain = false): self
    {
        return $this->add(ActivityType::DRIVING, $duration, $source, $uncertain);
    }

    public function work(string $duration, ActivitySource $source = ActivitySource::LOCAL, bool $uncertain = false): self
    {
        return $this->add(ActivityType::WORK, $duration, $source, $uncertain);
    }

    public function available(string $duration, ActivitySource $source = ActivitySource::LOCAL, bool $uncertain = false): self
    {
        return $this->add(ActivityType::AVAILABILITY, $duration, $source, $uncertain);
    }

    public function rest(string $duration, ActivitySource $source = ActivitySource::LOCAL, bool $uncertain = false): self
    {
        return $this->add(ActivityType::REST, $duration, $source, $uncertain);
    }

    public function unknown(string $duration): self
    {
        return $this->add(ActivityType::UNKNOWN, $duration, ActivitySource::UNKNOWN, true);
    }

    /** Advance time without recording anything (missing data). */
    public function gap(string $duration): self
    {
        $this->cursor = $this->cursor->modify('+'.self::seconds($duration).' seconds');

        return $this;
    }

    public function add(
        ActivityType $type,
        string $duration,
        ActivitySource $source = ActivitySource::LOCAL,
        bool $uncertain = false,
        ?string $vehicleId = 'test_vehicle_01',
    ): self {
        $start = $this->cursor;
        $end = $start->modify('+'.self::seconds($duration).' seconds');

        $this->activities[] = new Activity(
            driverId: $this->driverId,
            vehicleId: $vehicleId,
            type: $type,
            start: $start,
            end: $end,
            source: $source,
            uncertain: $uncertain,
            sourceEventIds: [Activity::key($this->driverId, $start->getTimestamp(), $end->getTimestamp(), $type->value, $vehicleId)],
            rawStatus: $type->value,
        );

        $this->cursor = $end;

        return $this;
    }

    public function cursor(): DateTimeImmutable
    {
        return $this->cursor;
    }

    /** @return list<Activity> */
    public function activities(): array
    {
        return $this->activities;
    }

    public function build(?TachoConfig $config = null, ?Period $coverage = null): Timeline
    {
        return (new TimelineBuilder($config ?? TachoConfig::defaults()))->build($this->driverId, $this->activities, $coverage);
    }

    /** Parses "4h30m", "45m", "11h", "1d2h", "30s". */
    public static function seconds(string $duration): int
    {
        if (! preg_match_all('/(\d+)\s*([dhms])/i', $duration, $matches, PREG_SET_ORDER) || preg_replace('/(\d+)\s*([dhms])/i', '', $duration) !== '') {
            throw new InvalidArgumentException("Invalid duration [{$duration}].");
        }

        $units = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];

        return array_sum(array_map(fn ($m) => (int) $m[1] * $units[strtolower($m[2])], $matches));
    }
}
