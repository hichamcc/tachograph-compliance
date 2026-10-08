<?php

namespace App\Tachograph\Normalization;

use App\Tachograph\Data\Activity;
use App\Tachograph\Data\TachoEvent;

/**
 * Normalized activities, events and data-quality issues, grouped by driver.
 * Exact duplicates (same source event key) are dropped on insert.
 */
final class NormalizationResult
{
    /** @var array<string, array<string, Activity>> */
    private array $activities = [];

    /** @var array<string, array<string, TachoEvent>> */
    private array $events = [];

    /** @var list<DataIssue> */
    private array $issues = [];

    public int $recordsRetrieved = 0;

    public int $recordsInvalid = 0;

    public function addActivity(Activity $activity): bool
    {
        $key = $activity->sourceEventIds[0] ?? throw new \LogicException('Activity has no source event key.');

        if (isset($this->activities[$activity->driverId][$key])) {
            $this->addIssue(DataIssue::make(
                IssueType::DUPLICATE_EVENT,
                'Exact duplicate record removed.',
                $activity->driverId,
                $activity->start,
                $activity->end,
                ['status' => $activity->rawStatus],
            ));

            return false;
        }

        $this->activities[$activity->driverId][$key] = $activity;

        return true;
    }

    public function addEvent(TachoEvent $event): void
    {
        $this->events[$event->driverId][$event->sourceEventId] = $event;
    }

    public function addIssue(DataIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    /** Record dropped because of an ERROR-level issue. */
    public function reject(DataIssue $issue): void
    {
        $this->recordsInvalid++;
        $this->addIssue($issue);
    }

    /** @return list<string> */
    public function driverIds(): array
    {
        return array_keys($this->activities + $this->events);
    }

    /** @return list<Activity> sorted by start */
    public function activities(?string $driverId = null): array
    {
        $activities = $driverId === null
            ? array_merge(...array_map('array_values', array_values($this->activities ?: [[]])))
            : array_values($this->activities[$driverId] ?? []);

        usort($activities, fn (Activity $a, Activity $b) => [$a->driverId, $a->start, $a->end] <=> [$b->driverId, $b->start, $b->end]);

        return $activities;
    }

    /** @return list<TachoEvent> sorted by time */
    public function events(?string $driverId = null): array
    {
        $events = $driverId === null
            ? array_merge(...array_map('array_values', array_values($this->events ?: [[]])))
            : array_values($this->events[$driverId] ?? []);

        usort($events, fn (TachoEvent $a, TachoEvent $b) => $a->occurredAt <=> $b->occurredAt);

        return $events;
    }

    /**
     * Issues for one driver, or all issues when no driver is given.
     *
     * @return list<DataIssue>
     */
    public function issues(?string $driverId = null): array
    {
        if ($driverId === null) {
            return $this->issues;
        }

        return array_values(array_filter($this->issues, fn (DataIssue $i) => $i->driverId === $driverId));
    }

    /** @param callable(Activity): Activity $callback */
    public function mapActivities(string $driverId, callable $callback): void
    {
        foreach ($this->activities[$driverId] ?? [] as $key => $activity) {
            $this->activities[$driverId][$key] = $callback($activity);
        }
    }

    public function activityCount(): int
    {
        return array_sum(array_map('count', $this->activities));
    }
}
