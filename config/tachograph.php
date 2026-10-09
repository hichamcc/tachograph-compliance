<?php

return [

    // Display only. All calculations are UTC.
    'display_timezone' => env('TACHO_TIMEZONE_DISPLAY', 'Europe/Copenhagen'),

    // Timezone used to define "Monday 00:00". Tachographs record in UTC;
    // confirm with national enforcement practice.
    'week_timezone' => env('TACHO_WEEK_TIMEZONE', 'UTC'),
    'week_starts_on' => 'monday',

    // Drivers with driving/work in this many weeks are "active": listed by default, refreshed
    // every run of tacho:refresh. Others are hidden behind a toggle and refreshed once a day.
    'active_weeks' => (int) env('TACHO_ACTIVE_WEEKS', 5),
    'refresh_inactive_hours' => 24,
    'refresh_interval_minutes' => 120, // active drivers are refreshed about this often

    // "Call URL" cron: secret token (>= 32 chars; empty = disabled) and seconds of work per call.
    'cron_token' => env('TACHO_CRON_TOKEN', ''),
    'cron_seconds' => (int) env('TACHO_CRON_SECONDS', 25),

    // How far back to fetch before the report start (history for 2-week & compensation rules).
    'history_days' => 28,
    'fetch_chunk_days' => 28, // must be <= 31 (Mapon limit)

    // Cross-check against Mapon's own counters (unit_data/driving_time_extended): driving-time
    // differences up to this many minutes are not reported.
    'crosscheck_tolerance_minutes' => 30,

    // Day-by-day activity timeline chart on the report page (pending client review).
    'show_timeline' => (bool) env('TACHO_SHOW_TIMELINE', false),

    // Allow uploading local JSON fixtures from the web UI (disable in production).
    'allow_import' => (bool) env('TACHO_ALLOW_IMPORT', false),

    // Raw Mapon responses are deleted after this many days.
    'raw_retention_days' => (int) env('TACHO_RAW_RETENTION_DAYS', 90),

    // Paths on the private "local" disk (storage/app/private).
    'raw_payload_path' => 'mapon/raw',
    'report_path' => 'reports',

    'data' => [
        'gap_tolerance_seconds' => 60, // gaps <= this are treated as contiguous
        'treat_can_source_as' => 'uncertain', // 'certain' | 'uncertain'
        'treat_card_out_rest_as' => 'uncertain_rest', // 'rest' | 'uncertain_rest' | 'unknown'
        'availability_counts_as_break' => false,
    ],

    // Boundary policy: limits are INCLUSIVE.
    // Driving <= limit is compliant; rest/break >= minimum is compliant.
    'boundaries_inclusive' => true,

    'rules' => [
        'max_daily_driving_hours' => 9,
        'extended_daily_driving_hours' => 10,
        'maximum_extended_daily_driving_days_per_week' => 2,
        'max_weekly_driving_hours' => 56,
        'max_two_week_driving_hours' => 90,

        'driving_before_break_hours' => 4.5,
        'standard_break_minutes' => 45,
        'split_break_first_minutes' => 15,
        'split_break_second_minutes' => 30,

        'regular_daily_rest_hours' => 11,
        'reduced_daily_rest_minimum_hours' => 9,
        'max_reduced_daily_rests_between_weekly_rests' => 3,
        'split_daily_rest_first_hours' => 3, // 3h + 9h split regular daily rest
        'split_daily_rest_second_hours' => 9,
        'daily_rest_reference_hours' => 24,

        'regular_weekly_rest_hours' => 45,
        'reduced_weekly_rest_minimum_hours' => 24,
        'max_24h_periods_before_weekly_rest' => 6,
        'compensation_deadline_weeks' => 3,
    ],

    // Reported as "not evaluated" in every report (see docs/ASSUMPTIONS.md).
    'not_evaluated' => [
        'FERRY_TRAIN',
        'MULTI_MANNING',
        'TWO_CONSECUTIVE_REDUCED_WEEKLY_RESTS',
        'RETURN_HOME_4_WEEKS',
        'REGULAR_WEEKLY_REST_IN_VEHICLE',
        'NATIONAL_DEROGATIONS',
        'WORKING_TIME_DIRECTIVE',
    ],
];
