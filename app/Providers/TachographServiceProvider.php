<?php

namespace App\Providers;

use App\Tachograph\Compliance\ComplianceEngine;
use App\Tachograph\Config\TachoConfig;
use App\Tachograph\Rules;
use Illuminate\Support\ServiceProvider;

class TachographServiceProvider extends ServiceProvider
{
    /** Remove a rule from this list to disable it. */
    public const RULES = [
        Rules\BreakRule::class,
        Rules\DailyDrivingRule::class,
        Rules\WeeklyDrivingRule::class,
        Rules\TwoWeekDrivingRule::class,
        Rules\DailyRestRule::class,
        Rules\WeeklyRestRule::class,
    ];

    public function register(): void
    {
        $this->app->singleton(TachoConfig::class, fn ($app) => TachoConfig::fromArray($app['config']->get('tachograph')));

        $this->app->tag(self::RULES, 'tachograph.rules');

        $this->app->bind(ComplianceEngine::class, fn ($app) => new ComplianceEngine(
            $app->tagged('tachograph.rules'),
            $app->make(TachoConfig::class),
        ));
    }
}
