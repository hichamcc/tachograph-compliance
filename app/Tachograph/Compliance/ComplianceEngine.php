<?php

namespace App\Tachograph\Compliance;

use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Data\Period;
use App\Tachograph\Data\Timeline;
use App\Tachograph\Rules\Rule;

/**
 * Runs every registered rule over a timeline. Depends only on Timeline/Activity —
 * never on Mapon or Eloquent.
 */
final class ComplianceEngine
{
    /** @param iterable<Rule> $rules */
    public function __construct(
        private readonly iterable $rules,
        private readonly TachoConfig $config,
    ) {}

    /** @return list<Finding> sorted by period start, then rule */
    public function evaluate(Timeline $timeline, Period $reportPeriod): array
    {
        $context = new RuleContext($timeline, $reportPeriod, $this->config);
        $findings = [];

        foreach ($this->rules as $rule) {
            array_push($findings, ...$rule->evaluate($context));
        }

        usort($findings, fn (Finding $a, Finding $b) => [$a->periodStart, $a->rule] <=> [$b->periodStart, $b->rule]);

        return $findings;
    }

    /** @return list<string> */
    public function notEvaluated(): array
    {
        return $this->config->notEvaluated;
    }
}
