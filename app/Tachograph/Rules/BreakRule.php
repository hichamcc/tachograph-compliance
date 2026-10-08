<?php

namespace App\Tachograph\Rules;

use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Data\Activity;
use App\Tachograph\Data\ActivityType;
use App\Tachograph\Data\Period;

/**
 * Art. 7: after 4.5h of driving a break of >= 45 min, or 15 min followed by 30 min (in that
 * order). Driving between the two split parts counts toward the 4.5h. WORK does not reset;
 * AVAILABILITY only if configured. Daily/weekly rests also qualify.
 */
final class BreakRule extends AbstractRule
{
    public const CODE = 'BREAK_AFTER_4_5_HOURS';

    public function codes(): array
    {
        return [self::CODE];
    }

    public function evaluate(RuleContext $context): array
    {
        $config = $context->config;
        $limit = $config->hours('driving_before_break_hours');
        $standard = $config->minutes('standard_break_minutes');
        $firstPart = $config->minutes('split_break_first_minutes');
        $secondPart = $config->minutes('split_break_second_minutes');

        $findings = [];
        $state = $this->freshState();

        foreach ($context->timeline->activities() as $activity) {
            $duration = $activity->durationSeconds();

            if ($activity->type === ActivityType::DRIVING) {
                $state['start'] ??= $activity->start;
                $state['driving'] += $duration;
                $state['sinceUnknown'] += $duration;
                $state['last'] = $activity->end;
                $state['evidence'][] = $activity;

                continue;
            }

            if ($activity->type === ActivityType::UNKNOWN) {
                // Unknown time long enough to have been (part of) a break.
                if ($duration >= $firstPart && $state['start'] !== null) {
                    $state['confirmed'] = max($state['confirmed'], $state['sinceUnknown']);
                    $state['sinceUnknown'] = 0;
                    $state['hadUnknown'] = true;
                }

                continue;
            }

            $countsAsBreak = $activity->type->isRestLike()
                || ($activity->type === ActivityType::AVAILABILITY && $config->availabilityCountsAsBreak);

            if (! $countsAsBreak) {
                continue; // WORK (and AVAILABILITY by default): no reset, no driving
            }

            $qualifies = $duration >= $standard || ($state['firstSplit'] !== null && $duration >= $secondPart);

            if ($qualifies) {
                if ($state['start'] !== null) {
                    $state['evidence'][] = $activity;
                    $findings[] = $this->emit($context, $state, $activity->start, $limit, $activity, false);
                }
                $state = $this->freshState();
            } elseif ($state['firstSplit'] === null && $duration >= $firstPart) {
                $state['firstSplit'] = $activity;
            }
        }

        if ($state['start'] !== null) {
            $findings[] = $this->emit($context, $state, $state['last'], $limit, null, true);
        }

        return array_values(array_filter($findings));
    }

    private function freshState(): array
    {
        return [
            'start' => null, 'last' => null, 'driving' => 0, 'evidence' => [],
            'firstSplit' => null, 'hadUnknown' => false, 'confirmed' => 0, 'sinceUnknown' => 0,
        ];
    }

    private function emit(RuleContext $context, array $state, \DateTimeImmutable $end, int $limit, ?Activity $break, bool $open)
    {
        $period = new Period($state['start'], $end);

        if (! $context->overlapsReport($period)) {
            return null;
        }

        $driving = $state['driving'];
        $confirmedDriving = max($state['confirmed'], $state['sinceUnknown']);

        $status = match (true) {
            $driving <= $limit => FindingStatus::COMPLIANT,
            $confirmedDriving > $limit || ! $state['hadUnknown'] => FindingStatus::VIOLATION,
            default => FindingStatus::INCOMPLETE_DATA,
        };

        $splitUsed = $break !== null && $state['firstSplit'] !== null && $break->durationSeconds() < $context->config->minutes('standard_break_minutes');

        $message = match ($status) {
            FindingStatus::COMPLIANT => sprintf('%s driving before %s.', self::hm($driving), $open ? 'end of data' : ($splitUsed ? 'a split break' : 'a qualifying break')),
            FindingStatus::VIOLATION => sprintf('%s driving without a qualifying break (max %s).', self::hm($driving), self::hm($limit)),
            default => sprintf('%s driving; unknown data in between may have been a break.', self::hm($driving)),
        };

        $evidence = $state['evidence'];
        if ($state['firstSplit']) {
            $evidence[] = $state['firstSplit'];
        }

        return $this->finding(
            $context, self::CODE, $status, $period->start, $period->end,
            self::hours($driving), self::hours($limit), 'hours', $message, $evidence,
            ['split_break' => $splitUsed, 'open' => $open, 'break_minutes' => $break ? self::minutes($break->durationSeconds()) : null],
        );
    }
}
