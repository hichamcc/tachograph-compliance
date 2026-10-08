<?php

namespace App\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;

/**
 * Art. 8(6), 8(7):
 *  - WEEKLY_REST: a weekly rest must start no later than six 24h periods after the end of the
 *    previous one; >= 45h is regular, >= 24h reduced.
 *  - WEEKLY_REST_PATTERN: in any two consecutive weeks, two regular weekly rests, or one
 *    regular + one reduced. A rest spanning two weeks counts in the week holding its larger part.
 *  - WEEKLY_REST_COMPENSATION: a reduced rest owes (45h - actual), en bloc, attached to another
 *    rest of >= 9h, before the end of the 3rd week following.
 * Not evaluated: two consecutive reduced rests (international), return home, rest in vehicle.
 */
final class WeeklyRestRule extends AbstractRule
{
    public const CODE = 'WEEKLY_REST';

    public const PATTERN_CODE = 'WEEKLY_REST_PATTERN';

    public const COMPENSATION_CODE = 'WEEKLY_REST_COMPENSATION';

    public function codes(): array
    {
        return [self::CODE, self::PATTERN_CODE, self::COMPENSATION_CODE];
    }

    public function evaluate(RuleContext $context): array
    {
        $rests = array_values(array_filter(
            $context->timeline->activities(),
            fn (Activity $a) => $a->type === ActivityType::WEEKLY_REST,
        ));

        return [
            ...$this->deadlines($context, $rests),
            ...$this->pattern($context, $rests),
            ...$this->compensation($context, $rests),
        ];
    }

    /** @param list<Activity> $rests */
    private function deadlines(RuleContext $context, array $rests): array
    {
        $config = $context->config;
        $regular = $config->hours('regular_weekly_rest_hours');
        $reduced = $config->hours('reduced_weekly_rest_minimum_hours');
        $maxGap = $config->count('max_24h_periods_before_weekly_rest') * 86400;
        $findings = [];
        $previous = null;

        foreach ($rests as $rest) {
            $type = $rest->durationSeconds() >= $regular ? 'regular' : 'reduced';
            $details = ['rest_type' => $type, 'rest_hours' => self::hours($rest->durationSeconds())];

            if ($previous === null) {
                if ($context->overlapsReport($rest->period())) {
                    $findings[] = $this->finding(
                        $context, self::CODE, FindingStatus::COMPLIANT, $rest->start, $rest->end,
                        self::hours($rest->durationSeconds()), self::hours($type === 'regular' ? $regular : $reduced), 'hours',
                        sprintf('%s weekly rest of %s (previous weekly rest not in data).', ucfirst($type), self::hm($rest->durationSeconds())),
                        [$rest], $details + ['previous_known' => false],
                    );
                }
                $previous = $rest;

                continue;
            }

            $since = new Period($previous->end, $rest->start);
            $deadline = $previous->end->modify("+{$maxGap} seconds");
            $late = $rest->start > $deadline;

            if ($context->overlapsReport($rest->period()) || ($late && $context->overlapsReport($since))) {
                $status = ! $late
                    ? FindingStatus::COMPLIANT
                    : ($context->longestUnknownSeconds($since) >= $reduced ? FindingStatus::INCOMPLETE_DATA : FindingStatus::VIOLATION);

                $findings[] = $this->finding(
                    $context, self::CODE, $status,
                    $late ? $previous->end : $rest->start,
                    $late ? $rest->start : $rest->end,
                    self::hours($since->durationSeconds()), self::hours($maxGap), 'hours',
                    match ($status) {
                        FindingStatus::COMPLIANT => sprintf('%s weekly rest of %s, started %s after the previous one.', ucfirst($type), self::hm($rest->durationSeconds()), self::hm($since->durationSeconds())),
                        FindingStatus::VIOLATION => sprintf('Weekly rest started %s after the previous one (max %s).', self::hm($since->durationSeconds()), self::hm($maxGap)),
                        default => sprintf('Weekly rest started %s after the previous one; unknown data in between may have been a weekly rest.', self::hm($since->durationSeconds())),
                    },
                    [$previous, $rest],
                    $details + ['previous_known' => true, 'deadline' => $deadline->format('Y-m-d\TH:i:s\Z')],
                );
            }

            $previous = $rest;
        }

        $this->appendMissingRest($context, $findings, $previous, $maxGap, $reduced);

        return $findings;
    }

    /** No weekly rest at all after the last one (or in the whole data) by the deadline. */
    private function appendMissingRest(RuleContext $context, array &$findings, ?Activity $previous, int $maxGap, int $reduced): void
    {
        $horizon = $context->horizon();
        $from = $previous?->end ?? $context->coverage()->start;
        $deadline = $from->modify("+{$maxGap} seconds");

        if ($horizon <= $deadline) {
            return;
        }

        $since = new Period($from, $horizon);

        if (! $context->overlapsReport(new Period($deadline, $horizon))) {
            return;
        }

        $status = $context->longestUnknownSeconds($since) >= $reduced ? FindingStatus::INCOMPLETE_DATA : FindingStatus::VIOLATION;

        $findings[] = $this->finding(
            $context, self::CODE, $status, $from, $horizon,
            self::hours($since->durationSeconds()), self::hours($maxGap), 'hours',
            $previous
                ? sprintf('No weekly rest started within %s after the previous one.', self::hm($maxGap))
                : sprintf('No weekly rest in %s of data.', self::hm($since->durationSeconds())),
            array_values(array_filter([$previous])),
            ['previous_known' => $previous !== null, 'deadline' => $deadline->format('Y-m-d\TH:i:s\Z')],
        );
    }

    /** @param list<Activity> $rests */
    private function pattern(RuleContext $context, array $rests): array
    {
        $config = $context->config;
        $regular = $config->hours('regular_weekly_rest_hours');
        $reduced = $config->hours('reduced_weekly_rest_minimum_hours');
        $findings = [];

        // week start ts => list of [isRegular, rest]
        $byWeek = [];
        foreach ($rests as $rest) {
            // A long rest (e.g. holidays) is a regular weekly rest in every week holding >= 45h of it.
            $counted = false;
            foreach ($context->calendar->weeksOverlapping($rest->period()) as $week) {
                if ($rest->clip($week)?->durationSeconds() >= $regular) {
                    $byWeek[$week->start->getTimestamp()][] = [true, $rest];
                    $counted = true;
                }
            }

            // Otherwise it counts once, in the week holding its larger part.
            if (! $counted) {
                $byWeek[$this->assignedWeek($context, $rest)->start->getTimestamp()][] = [$rest->durationSeconds() >= $regular, $rest];
            }
        }

        foreach ($context->reportWeeks() as $week) {
            if ($week->end > $context->horizon()) {
                continue; // week not finished yet
            }

            $previous = $context->calendar->previous($week);
            $span = new Period($previous->start, $week->end);
            $entries = [...($byWeek[$previous->start->getTimestamp()] ?? []), ...($byWeek[$week->start->getTimestamp()] ?? [])];
            $regularCount = count(array_filter($entries, fn (array $e) => $e[0]));
            $reducedCount = count($entries) - $regularCount;
            $ok = $regularCount >= 2 || ($regularCount >= 1 && count($entries) >= 2);
            $inPair = array_values(array_unique(array_map(fn (array $e) => $e[1], $entries), SORT_REGULAR));
            $label = $context->calendar->label($previous).' + '.$context->calendar->label($week);

            $status = match (true) {
                $ok => FindingStatus::COMPLIANT,
                $context->coverage()->start > $previous->start, $context->longestUnknownSeconds($span) >= $reduced => FindingStatus::INCOMPLETE_DATA,
                default => FindingStatus::VIOLATION,
            };

            $findings[] = $this->finding(
                $context, self::PATTERN_CODE, $status, $previous->start, $week->end,
                count($inPair), 2, 'count',
                match ($status) {
                    FindingStatus::COMPLIANT => sprintf('%s: %d regular + %d reduced weekly rest(s).', $label, $regularCount, $reducedCount),
                    FindingStatus::VIOLATION => sprintf('%s: %d regular + %d reduced weekly rest(s); need 2 regular or 1 regular + 1 reduced.', $label, $regularCount, $reducedCount),
                    default => sprintf('%s: %d regular + %d reduced weekly rest(s) in the available data.', $label, $regularCount, $reducedCount),
                },
                $inPair,
                ['weeks' => $label, 'regular' => $regularCount, 'reduced' => $reducedCount],
            );
        }

        return $findings;
    }

    /** @param list<Activity> $rests */
    private function compensation(RuleContext $context, array $rests): array
    {
        $config = $context->config;
        $regular = $config->hours('regular_weekly_rest_hours');
        $dailyMin = $config->hours('reduced_daily_rest_minimum_hours');
        $weeks = $config->count('compensation_deadline_weeks');
        $findings = [];
        $used = [];

        $candidates = array_values(array_filter(
            $context->timeline->activities(),
            fn (Activity $a) => $a->type === ActivityType::DAILY_REST || $a->type === ActivityType::WEEKLY_REST,
        ));

        foreach ($rests as $rest) {
            $duration = $rest->durationSeconds();

            if ($duration >= $regular) {
                continue;
            }

            $owed = $regular - $duration;
            $deadline = $this->assignedWeek($context, $rest)->end->modify("+{$weeks} weeks");
            $window = new Period($rest->end, $deadline);

            if (! $context->overlapsReport(new Period($rest->start, $deadline))) {
                continue;
            }

            $completedBy = null;
            foreach ($candidates as $i => $candidate) {
                if (isset($used[$i]) || $candidate->start < $rest->end || $candidate->end > $deadline) {
                    continue;
                }

                // Compensation must be attached en bloc to another rest of at least 9h.
                $base = $candidate->type === ActivityType::WEEKLY_REST ? $regular : $dailyMin;
                if ($candidate->durationSeconds() - $base >= $owed) {
                    $completedBy = $candidate;
                    $used[$i] = true;
                    break;
                }
            }

            $status = match (true) {
                $completedBy !== null => FindingStatus::COMPLIANT,
                $deadline > $context->horizon() => FindingStatus::WARNING,
                $context->longestUnknownSeconds($window) >= $owed => FindingStatus::INCOMPLETE_DATA,
                default => FindingStatus::VIOLATION,
            };

            $label = match ($status) {
                FindingStatus::COMPLIANT => 'COMPLETED',
                FindingStatus::WARNING => 'PENDING',
                FindingStatus::VIOLATION => 'OVERDUE',
                default => 'UNKNOWN',
            };

            $findings[] = $this->finding(
                $context, self::COMPENSATION_CODE, $status, $rest->start, $completedBy?->end ?? $deadline,
                self::hours($owed), null, 'hours',
                match ($label) {
                    'COMPLETED' => sprintf('Reduced weekly rest compensated (%s) with the rest starting %s.', self::hm($owed), $completedBy->start->format('Y-m-d H:i').' UTC'),
                    'PENDING' => sprintf('Compensation of %s due by %s.', self::hm($owed), $deadline->format('Y-m-d H:i').' UTC'),
                    'OVERDUE' => sprintf('Compensation of %s was not taken by %s.', self::hm($owed), $deadline->format('Y-m-d H:i').' UTC'),
                    default => sprintf('Compensation of %s: unknown data before %s may contain it.', self::hm($owed), $deadline->format('Y-m-d H:i').' UTC'),
                },
                array_values(array_filter([$rest, $completedBy])),
                [
                    'compensation_status' => $label,
                    'owed_hours' => self::hours($owed),
                    'due_by' => $deadline->format('Y-m-d\TH:i:s\Z'),
                    'completed_at' => $completedBy?->end->format('Y-m-d\TH:i:s\Z'),
                ],
            );
        }

        return $findings;
    }

    /** The fixed week holding the larger part of the rest (ties → earlier week). */
    private function assignedWeek(RuleContext $context, Activity $rest): Period
    {
        $first = $context->calendar->weekOf($rest->start);

        if ($rest->end <= $first->end) {
            return $first;
        }

        $inFirst = $first->end->getTimestamp() - $rest->start->getTimestamp();
        $inNext = $rest->end->getTimestamp() - $first->end->getTimestamp();

        return $inNext > $inFirst ? $context->calendar->next($first) : $first;
    }
}
