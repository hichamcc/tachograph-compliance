<?php

namespace App\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;

/**
 * Art. 8(2), 8(4): within 24h after the end of the previous daily/weekly rest, a new daily
 * rest must be taken. Only the part inside that window counts.
 *   >= 11h                      → regular
 *   >= 3h break then >= 9h rest → regular (split)
 *   >= 9h and < 11h             → reduced (max 3 between two weekly rests)
 *   < 9h                        → violation
 */
final class DailyRestRule extends AbstractRule
{
    public const CODE = 'DAILY_REST';

    public const REDUCTIONS_CODE = 'DAILY_REST_REDUCTIONS';

    public function codes(): array
    {
        return [self::CODE, self::REDUCTIONS_CODE];
    }

    public function evaluate(RuleContext $context): array
    {
        $config = $context->config;
        $regular = $config->hours('regular_daily_rest_hours');
        $reducedMin = $config->hours('reduced_daily_rest_minimum_hours');
        $splitFirst = $config->hours('split_daily_rest_first_hours');
        $splitSecond = $config->hours('split_daily_rest_second_hours');
        $window = $config->hours('daily_rest_reference_hours');
        $maxReduced = $config->count('max_reduced_daily_rests_between_weekly_rests');

        $findings = [];
        $reductions = 0;
        $weeklyRestSeen = false; // before the first weekly rest the count is a lower bound

        foreach ($context->shifts() as $shift) {
            if ($shift->previousRest?->type === ActivityType::WEEKLY_REST) {
                $reductions = 0;
                $weeklyRestSeen = true;
            }

            $reportable = $context->inReport($shift->start);

            if (! $shift->startKnown()) {
                if ($reportable) {
                    $findings[] = $this->finding(
                        $context, self::CODE, FindingStatus::INCOMPLETE_DATA, $shift->start, $shift->end,
                        null, self::hours($reducedMin), 'hours',
                        'The rest before this shift is not in the available data; daily rest window unknown.',
                        details: ['rest_type' => 'unknown'],
                    );
                }

                continue;
            }

            $windowEnd = $shift->start->modify("+{$window} seconds");
            $rest = $shift->nextRest;

            if ($rest === null && $context->horizon() < $windowEnd) {
                continue; // shift still running; 24h window not over yet
            }

            $inWindow = $rest !== null && $rest->start < $windowEnd
                ? min($rest->end, $windowEnd)->getTimestamp() - $rest->start->getTimestamp()
                : 0;

            $splitPart = $this->splitFirstPart($shift->activities, $splitFirst, $windowEnd);

            [$type, $status] = match (true) {
                $rest?->type === ActivityType::WEEKLY_REST && $inWindow >= $reducedMin => ['weekly', FindingStatus::COMPLIANT],
                $inWindow >= $regular => ['regular', FindingStatus::COMPLIANT],
                $splitPart !== null && $inWindow >= $splitSecond => ['split', FindingStatus::COMPLIANT],
                $inWindow >= $reducedMin => ['reduced', FindingStatus::COMPLIANT],
                default => ['insufficient', $this->insufficientStatus($context, new Period($shift->start, $windowEnd), $inWindow, $reducedMin)],
            };

            if ($type === 'reduced') {
                $reductions++;
            }

            // For an insufficient rest, report (and cite) the longest rest actually taken in the window.
            $longest = $type === 'insufficient' ? $this->longestRest($context, new Period($shift->start, $windowEnd)) : null;
            $measured = max($inWindow, $longest?->durationSeconds() ?? 0);

            if ($reportable) {
                $end = $rest !== null ? min($rest->end, $windowEnd) : $windowEnd;
                $evidence = array_values(array_unique(array_filter([$splitPart, $longest, $rest]), SORT_REGULAR));

                $findings[] = $this->finding(
                    $context, self::CODE, $status, $shift->start, $end,
                    self::hours($measured), self::hours($type === 'reduced' || $type === 'insufficient' ? $reducedMin : $regular), 'hours',
                    match ($type) {
                        'weekly' => 'Shift ended with a weekly rest.',
                        'regular' => sprintf('Regular daily rest of %s within 24h.', self::hm($inWindow)),
                        'split' => sprintf('Split daily rest: %s + %s within 24h.', self::hm($splitPart->durationSeconds()), self::hm($inWindow)),
                        'reduced' => sprintf('Reduced daily rest of %s (%d of %d reductions used).', self::hm($inWindow), $reductions, $maxReduced),
                        default => $status === FindingStatus::VIOLATION
                            ? sprintf('No daily rest within 24h after the previous rest: longest rest was %s (min %s).', self::hm($measured), self::hm($reducedMin))
                            : sprintf('No daily rest recorded within 24h (longest rest %s); unknown data may have been rest.', self::hm($measured)),
                    },
                    $evidence,
                    [
                        'rest_type' => $type,
                        'window_end' => $windowEnd->format('Y-m-d\TH:i:s\Z'),
                        'reductions_used' => $reductions,
                        'reductions_remaining' => max(0, $maxReduced - $reductions),
                        'reductions_count_complete' => $weeklyRestSeen,
                    ],
                );

                if ($type === 'reduced' && $reductions > $maxReduced) {
                    $findings[] = $this->finding(
                        $context, self::REDUCTIONS_CODE, FindingStatus::VIOLATION, $rest->start, $rest->end,
                        $reductions, $maxReduced, 'count',
                        sprintf('Reduced daily rest #%d since the last weekly rest (max %d).', $reductions, $maxReduced),
                        [$rest],
                        ['reductions_used' => $reductions],
                    );
                }
            }
        }

        return $findings;
    }

    private function longestRest(RuleContext $context, Period $window): ?Activity
    {
        $longest = null;

        foreach ($context->timeline->between($window) as $activity) {
            if ($activity->type->isRestLike() && $activity->durationSeconds() > ($longest?->durationSeconds() ?? 0)) {
                $longest = $activity;
            }
        }

        return $longest;
    }

    /** @param list<Activity> $activities */
    private function splitFirstPart(array $activities, int $minimum, \DateTimeImmutable $windowEnd): ?Activity
    {
        foreach ($activities as $activity) {
            if ($activity->type === ActivityType::BREAK && $activity->durationSeconds() >= $minimum && $activity->end <= $windowEnd) {
                return $activity;
            }
        }

        return null;
    }

    private function insufficientStatus(RuleContext $context, Period $window, int $inWindow, int $reducedMin): FindingStatus
    {
        $span = $context->untilHorizon($window) ?? $window;

        return $context->longestUnknownSeconds($span) + $inWindow >= $reducedMin
            ? FindingStatus::INCOMPLETE_DATA
            : FindingStatus::VIOLATION;
    }
}
