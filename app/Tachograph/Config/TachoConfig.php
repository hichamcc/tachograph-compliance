<?php

namespace App\Tachograph\Config;

use InvalidArgumentException;

/**
 * Typed view of config/tachograph.php. Durations are exposed in integer seconds
 * to avoid float rounding at rule boundaries.
 */
final readonly class TachoConfig
{
    public function __construct(
        public string $displayTimezone = 'Europe/Copenhagen',
        public string $weekTimezone = 'UTC',
        public int $gapToleranceSeconds = 60,
        public bool $canSourceUncertain = true,
        public string $cardOutRestAs = 'uncertain_rest',
        public bool $availabilityCountsAsBreak = false,
        public array $rules = [],
        public array $notEvaluated = [],
    ) {
        if (! in_array($cardOutRestAs, ['rest', 'uncertain_rest', 'unknown'], true)) {
            throw new InvalidArgumentException("Invalid treat_card_out_rest_as value [{$cardOutRestAs}].");
        }
    }

    public static function fromArray(array $config): self
    {
        $data = $config['data'] ?? [];

        return new self(
            displayTimezone: $config['display_timezone'] ?? 'Europe/Copenhagen',
            weekTimezone: $config['week_timezone'] ?? 'UTC',
            gapToleranceSeconds: (int) ($data['gap_tolerance_seconds'] ?? 60),
            canSourceUncertain: ($data['treat_can_source_as'] ?? 'uncertain') === 'uncertain',
            cardOutRestAs: $data['treat_card_out_rest_as'] ?? 'uncertain_rest',
            availabilityCountsAsBreak: (bool) ($data['availability_counts_as_break'] ?? false),
            rules: $config['rules'] ?? [],
            notEvaluated: $config['not_evaluated'] ?? [],
        );
    }

    /** Defaults straight from the config file (for tests without the framework). */
    public static function defaults(array $overrides = []): self
    {
        $config = require __DIR__.'/../../../config/tachograph.php';

        return self::fromArray(array_replace_recursive($config, $overrides));
    }

    public function hours(string $rule): int
    {
        return (int) round($this->rule($rule) * 3600);
    }

    public function minutes(string $rule): int
    {
        return (int) round($this->rule($rule) * 60);
    }

    public function count(string $rule): int
    {
        return (int) $this->rule($rule);
    }

    private function rule(string $rule): float
    {
        if (! array_key_exists($rule, $this->rules)) {
            throw new InvalidArgumentException("Unknown tachograph rule setting [{$rule}].");
        }

        return (float) $this->rules[$rule];
    }
}
