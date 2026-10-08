@use('App\Tachograph\Reporting\Format')
@php($s = $report->summary)
<section class="space-y-3">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 tacho-cards">
        @foreach ([
            [__('Violations'), $s['confirmed_violations'], $s['confirmed_violations'] ? 'text-red-600 dark:text-red-400' : ''],
            [__('Potential violations'), $s['potential_violations'], $s['potential_violations'] ? 'text-orange-600 dark:text-orange-400' : ''],
            [__('Not enough data'), $s['incomplete_data'], $s['incomplete_data'] ? 'text-amber-600 dark:text-amber-400' : ''],
            [__('Warnings'), $s['warnings'], ''],
        ] as [$label, $value, $class])
            <x-card>
                <x-text size="sm">{{ $label }}</x-text>
                <div class="text-2xl font-semibold tabular-nums {{ $class }}">{{ $value }}</div>
            </x-card>
        @endforeach
    </div>
    <x-card class="tacho-totals">
        <dl class="flex flex-wrap gap-x-10 gap-y-3">
            @foreach (['driving' => __('Driving'), 'work' => __('Work'), 'rest' => __('Rest')] as $group => $label)
                <div>
                    <dt class="text-sm text-gray-500 dark:text-white/60">{{ $label }}</dt>
                    <dd class="text-xl font-semibold tabular-nums">{{ Format::hm($s["total_{$group}_hours"]) }}</dd>
                </div>
            @endforeach
        </dl>
        <x-text size="sm" class="mt-3 tabular-nums">
            {{ __('Breaks') }} {{ Format::hm($s['total_break_hours']) }}
            @if ($s['total_availability_hours'] > 0) · {{ __('Availability') }} {{ Format::hm($s['total_availability_hours']) }} @endif
            @if ($s['total_unknown_hours'] > 0) · <span class="text-amber-700 dark:text-amber-300">{{ __('No data') }} {{ Format::hm($s['total_unknown_hours']) }}</span> @endif
            · {{ trans_choice('Vehicle|Vehicles', count($s['vehicles'])) }} {{ $s['vehicles'] ? implode(', ', $s['vehicles']) : '–' }}
            · {{ __('Data until') }} <x-tacho.time :value="$s['data_until']" :tz="$report->displayTimezone" />
        </x-text>
    </x-card>
</section>
