<?php

namespace Tests\Unit\Tachograph\Rules;

use App\Tachograph\Compliance\Finding;
use App\Tachograph\Compliance\FindingStatus;
use App\Tachograph\Compliance\RuleContext;
use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Period;
use App\Tachograph\Data\Timeline;
use App\Tachograph\Rules\Rule;
use PHPUnit\Framework\TestCase;
use Tests\Support\TimelineFactory;

abstract class RuleTestCase extends TestCase
{
    abstract protected function rule(): Rule;

    /** @return list<Finding> */
    protected function evaluate(Timeline $timeline, ?Period $report = null, array $config = []): array
    {
        $report ??= $timeline->coverage();

        return $this->rule()->evaluate(new RuleContext($timeline, $report, TachoConfig::defaults($config)));
    }

    /** @return list<Finding> */
    protected function findings(Timeline $timeline, string $code, ?Period $report = null): array
    {
        return array_values(array_filter($this->evaluate($timeline, $report), fn (Finding $f) => $f->rule === $code));
    }

    /** @return list<FindingStatus> */
    protected function statuses(array $findings): array
    {
        return array_map(fn (Finding $f) => $f->status, $findings);
    }

    protected function assertStatuses(array $expected, array $findings): void
    {
        $this->assertSame($expected, $this->statuses($findings), implode("\n", array_map(fn (Finding $f) => $f->status->value.': '.$f->message, $findings)));
    }

    /**
     * Work days of 11h duty (4h drive + 45m break + 4h drive + 2h15m work) separated by 13h
     * daily rests, with no trailing rest (so a following rest is not merged into it).
     * Spans 24 x days - 13 hours.
     */
    protected static function workDays(TimelineFactory $factory, int $days, string $driving = '4h'): TimelineFactory
    {
        for ($i = 0; $i < $days; $i++) {
            if ($i > 0) {
                $factory->rest('13h');
            }
            $factory->drive($driving)->rest('45m')->drive($driving)->work('2h15m');
        }

        return $factory;
    }
}
