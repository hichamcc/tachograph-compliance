<?php

namespace App\Tachograph\Normalization;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivitySource;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;
use App\Tachograph\Data\Timeline;
use DateTimeImmutable;

/**
 * Builds a contiguous, classified timeline from one driver's normalized activities:
 *  1. resolve overlaps (higher-priority source wins, except CAN driving/work beats card REST;
 *     conflicting equal-priority records → UNKNOWN)
 *  2. close gaps <= tolerance, fill larger gaps with UNKNOWN
 *  3. merge adjacent activities of the same type
 *  4. classify REST into BREAK / DAILY_REST / WEEKLY_REST
 */
final class TimelineBuilder
{
    private readonly RestClassifier $classifier;

    public function __construct(private readonly TachoConfig $config, ?RestClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new RestClassifier($config);
    }

    /**
     * @param  list<Activity>  $activities  one driver
     * @param  Period|null  $coverage  expected data window; missing data at its edges becomes UNKNOWN
     */
    public function build(string $driverId, array $activities, ?Period $coverage = null): Timeline
    {
        if ($coverage) {
            $activities = array_values(array_filter(array_map(fn (Activity $a) => $a->clip($coverage), $activities)));
        }

        $pieces = $this->resolveOverlaps($activities);
        $filled = $this->fillGaps($driverId, $pieces, $coverage);
        $merged = $this->merge($filled);

        return new Timeline($driverId, $this->classifier->classify($merged));
    }

    /**
     * Sweep over all boundaries; each elementary interval gets exactly one activity.
     *
     * @param  list<Activity>  $activities
     * @return list<Activity>
     */
    private function resolveOverlaps(array $activities): array
    {
        if ($activities === []) {
            return [];
        }

        usort($activities, fn (Activity $a, Activity $b) => [$a->start, $a->end] <=> [$b->start, $b->end]);

        $points = [];
        foreach ($activities as $activity) {
            $points[$activity->start->getTimestamp()] = $activity->start;
            $points[$activity->end->getTimestamp()] = $activity->end;
        }
        ksort($points);
        $points = array_values($points);

        $pieces = [];
        $active = [];
        $next = 0;
        $count = count($activities);

        for ($i = 0; $i < count($points) - 1; $i++) {
            [$from, $till] = [$points[$i], $points[$i + 1]];

            while ($next < $count && $activities[$next]->start <= $from) {
                $active[] = $activities[$next++];
            }

            $active = array_values(array_filter($active, fn (Activity $a) => $a->end > $from));
            $covering = array_values(array_filter($active, fn (Activity $a) => $a->start <= $from && $a->end >= $till));

            if ($covering !== []) {
                $pieces[] = $this->resolve($covering)->withTimes($from, $till);
            }
        }

        return $pieces;
    }

    /** @param non-empty-list<Activity> $covering */
    private function resolve(array $covering): Activity
    {
        if (count($covering) === 1) {
            return $covering[0];
        }

        // Mapon pads the day after the last card download with REST marked "ddd". When the
        // vehicle (CAN) shows the driver driving/working at that time, trust the CAN record
        // (it stays uncertain, so findings that depend on it are only "potential").
        $canActive = array_values(array_filter($covering, fn (Activity $a) => $a->source === ActivitySource::CAN && ! $a->type->isRestLike() && $a->type !== ActivityType::UNKNOWN));
        $onlyCardRest = array_filter($covering, fn (Activity $a) => $a->source !== ActivitySource::CAN && ! ($a->type->isRestLike() && $a->source === ActivitySource::DDD)) === [];

        if ($canActive !== [] && $onlyCardRest) {
            return $canActive[0]->with([
                'uncertain' => true,
                'sourceEventIds' => array_values(array_unique(array_merge(...array_map(fn (Activity $a) => $a->sourceEventIds, $covering)))),
            ]);
        }

        $best = max(array_map(fn (Activity $a) => $a->source->priority(), $covering));
        $winners = array_values(array_filter($covering, fn (Activity $a) => $a->source->priority() === $best));
        $first = $winners[0];
        $ids = array_values(array_unique(array_merge(...array_map(fn (Activity $a) => $a->sourceEventIds, $winners))));
        $sameType = count(array_unique(array_map(fn (Activity $a) => $a->type->value, $winners))) === 1;

        if ($sameType) {
            return $first->with([
                'uncertain' => in_array(true, array_map(fn (Activity $a) => $a->uncertain, $winners), true),
                'sourceEventIds' => $ids,
            ]);
        }

        // Equally reliable records disagree: we cannot tell what happened.
        return $first->with([
            'type' => ActivityType::UNKNOWN,
            'uncertain' => true,
            'sourceEventIds' => $ids,
            'rawStatus' => 'CONFLICT',
        ]);
    }

    /**
     * @param  list<Activity>  $pieces  sorted, non-overlapping
     * @return list<Activity>
     */
    private function fillGaps(string $driverId, array $pieces, ?Period $coverage): array
    {
        $tolerance = $this->config->gapToleranceSeconds;

        if ($pieces === []) {
            return $coverage && $coverage->durationSeconds() > 0
                ? [$this->unknown($driverId, $coverage->start, $coverage->end)]
                : [];
        }

        $out = [];

        if ($coverage && $pieces[0]->start > $coverage->start) {
            $gap = $pieces[0]->start->getTimestamp() - $coverage->start->getTimestamp();

            if ($gap <= $tolerance) {
                $pieces[0] = $pieces[0]->withTimes($coverage->start, $pieces[0]->end);
            } else {
                $out[] = $this->unknown($driverId, $coverage->start, $pieces[0]->start);
            }
        }

        foreach ($pieces as $piece) {
            $last = end($out) ?: null;

            if ($last !== null && $piece->start > $last->end) {
                $gap = $piece->start->getTimestamp() - $last->end->getTimestamp();

                if ($gap <= $tolerance) {
                    $out[array_key_last($out)] = $last->withTimes($last->start, $piece->start);
                } else {
                    $out[] = $this->unknown($driverId, $last->end, $piece->start);
                }
            }

            $out[] = $piece;
        }

        $last = end($out);

        if ($coverage && $coverage->end > $last->end) {
            $gap = $coverage->end->getTimestamp() - $last->end->getTimestamp();

            if ($gap <= $tolerance) {
                $out[array_key_last($out)] = $last->withTimes($last->start, $coverage->end);
            } else {
                $out[] = $this->unknown($driverId, $last->end, $coverage->end);
            }
        }

        return array_values($out);
    }

    /**
     * Merge adjacent activities of the same type. Rest is merged across vehicles and sources
     * (a rest is continuous regardless of where it was recorded); other types only when
     * source and vehicle also match.
     *
     * @param  list<Activity>  $activities
     * @return list<Activity>
     */
    private function merge(array $activities): array
    {
        $out = [];

        foreach ($activities as $activity) {
            $last = $out === [] ? null : $out[array_key_last($out)];

            if ($last !== null && $last->end == $activity->start && $this->canMerge($last, $activity)) {
                $out[array_key_last($out)] = $last->with([
                    'end' => $activity->end,
                    'vehicleId' => $last->vehicleId === $activity->vehicleId ? $last->vehicleId : null,
                    'source' => ActivitySource::weakest($last->source, $activity->source),
                    'uncertain' => $last->uncertain || $activity->uncertain,
                    'sourceEventIds' => array_values(array_unique([...$last->sourceEventIds, ...$activity->sourceEventIds])),
                    'rawStatus' => $last->rawStatus === $activity->rawStatus ? $last->rawStatus : null,
                    'rawPayloadId' => $last->rawPayloadId === $activity->rawPayloadId ? $last->rawPayloadId : null,
                ]);

                continue;
            }

            $out[] = $activity;
        }

        return $out;
    }

    private function canMerge(Activity $a, Activity $b): bool
    {
        if ($a->type !== $b->type) {
            return false;
        }

        if ($a->type->isRestLike() || $a->type === ActivityType::UNKNOWN) {
            return true;
        }

        return $a->source === $b->source && $a->vehicleId === $b->vehicleId && $a->uncertain === $b->uncertain;
    }

    private function unknown(string $driverId, DateTimeImmutable $start, DateTimeImmutable $end): Activity
    {
        return new Activity(
            driverId: $driverId,
            vehicleId: null,
            type: ActivityType::UNKNOWN,
            start: $start,
            end: $end,
            source: ActivitySource::UNKNOWN,
            uncertain: true,
            rawStatus: 'GAP',
        );
    }
}
