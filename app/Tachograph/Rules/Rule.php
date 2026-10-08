<?php

namespace App\Tachograph\Rules;

use App\Tachograph\Compliance\Finding;
use App\Tachograph\Compliance\RuleContext;

interface Rule
{
    /** Rule codes this rule can emit. */
    public function codes(): array;

    /** @return list<Finding> */
    public function evaluate(RuleContext $context): array;
}
