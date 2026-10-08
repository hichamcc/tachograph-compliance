@use('App\Tachograph\Reporting\Format')
<x-layouts.app :title="__('Dashboard')">
    <x-headbar :title="__('Dashboard')">
        <x-button size="sm" href="{{ route('tachograph.drivers.index') }}" before="phosphor-truck">{{ __('All drivers') }}</x-button>
    </x-headbar>

    <div class="mt-6 space-y-8">
        {{-- Headline --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach ([
                [__('Drivers with violations'), $headline['confirmed'], $headline['confirmed'] ? 'text-red-600 dark:text-red-400' : ''],
                [__('Potential violations'), $headline['potential'], $headline['potential'] ? 'text-orange-600 dark:text-orange-400' : ''],
                [__('Not enough data'), $headline['incomplete'], $headline['incomplete'] ? 'text-amber-600 dark:text-amber-400' : ''],
                [__('Checked (7 days)'), $headline['checked'].' / '.$headline['active'], ''],
            ] as [$label, $value, $class])
                <x-card>
                    <x-text size="sm">{{ $label }}</x-text>
                    <div class="text-3xl font-semibold tabular-nums {{ $class }}">{{ $value }}</div>
                </x-card>
            @endforeach
        </div>

        {{-- Attention --}}
        <section class="space-y-3">
            <x-heading size="lg" level="2">{{ __('Drivers needing attention') }}</x-heading>
            @if ($attention->isNotEmpty())
                <x-table>
                    <x-slot:head>
                        <x-table.row>
                            <x-table.col>{{ __('Driver') }}</x-table.col>
                            <x-table.col class="text-right">{{ __('Confirmed') }}</x-table.col>
                            <x-table.col class="text-right">{{ __('Potential') }}</x-table.col>
                            <x-table.col>{{ __('Rules') }}</x-table.col>
                            <x-table.col>{{ __('Period') }}</x-table.col>
                            <x-table.col class="text-right"></x-table.col>
                        </x-table.row>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($attention as $run)
                            <x-table.row>
                                <x-table.cell>
                                    <x-link href="{{ route('tachograph.drivers.show', $run->driver) }}" class="font-semibold">{{ $run->driver->label() }}</x-link>
                                </x-table.cell>
                                <x-table.cell class="text-right tabular-nums">
                                    @if ($run->confirmed)<x-badge size="sm" color="red">{{ $run->confirmed }}</x-badge>@else – @endif
                                </x-table.cell>
                                <x-table.cell class="text-right tabular-nums">
                                    @if ($run->potential)<x-badge size="sm" color="orange">{{ $run->potential }}</x-badge>@else – @endif
                                </x-table.cell>
                                <x-table.cell class="text-sm">{{ implode(', ', array_map(fn ($r) => Format::rule($r), $run->violation_rules)) }}</x-table.cell>
                                <x-table.cell class="tabular-nums whitespace-nowrap">{{ $run->period_start?->format('Y-m-d') }} – {{ $run->period_end?->subSecond()->format('Y-m-d') }}</x-table.cell>
                                <x-table.cell class="text-right"><x-link href="{{ route('tachograph.reports.show', $run) }}">{{ __('Report') }}</x-link></x-table.cell>
                            </x-table.row>
                        @endforeach
                    </x-slot:body>
                </x-table>
            @else
                <x-text>{{ $headline['checked'] ? __('No violations.') : __('No checks in the last :days days.', ['days' => $days]) }}</x-text>
            @endif
        </section>

        {{-- Compensation --}}
        @if ($compensation->isNotEmpty())
        <section class="space-y-3">
            <x-heading size="lg" level="2">{{ __('Weekly rest compensation due') }}</x-heading>
                <x-table>
                    <x-slot:head>
                        <x-table.row>
                            <x-table.col>{{ __('Driver') }}</x-table.col>
                            <x-table.col class="text-right">{{ __('Owed') }}</x-table.col>
                            <x-table.col>{{ __('Due by') }}</x-table.col>
                            <x-table.col class="text-right"></x-table.col>
                        </x-table.row>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($compensation as $finding)
                            @php($daysLeft = (int) floor(now()->diffInDays($finding->period_end)))
                            <x-table.row>
                                <x-table.cell>
                                    <x-link href="{{ route('tachograph.drivers.show', $finding->driver) }}" class="font-semibold">{{ $finding->driver->label() }}</x-link>
                                </x-table.cell>
                                <x-table.cell class="text-right tabular-nums">{{ Format::hm($finding->measured_value) }}</x-table.cell>
                                <x-table.cell class="whitespace-nowrap">
                                    <x-tacho.time :value="$finding->period_end->format('Y-m-d\TH:i:s\Z')" :tz="config('tachograph.display_timezone')" />
                                    <x-badge size="sm" :color="$daysLeft <= 3 ? 'amber' : 'gray'" class="ms-2">{{ trans_choice('{0} today|{1} in 1 day|[2,*] in :count days', $daysLeft) }}</x-badge>
                                </x-table.cell>
                                <x-table.cell class="text-right"><x-link href="{{ route('tachograph.reports.show', $finding->processing_run_id) }}">{{ __('Report') }}</x-link></x-table.cell>
                            </x-table.row>
                        @endforeach
                    </x-slot:body>
                </x-table>
        </section>
        @endif

        {{-- System status --}}
        <section class="space-y-3">
            <x-heading size="lg" level="2">{{ __('System status') }}</x-heading>
            <x-card>
                <dl class="grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-white/60">{{ __('Last driver sync') }}</dt>
                        <dd class="font-medium">@if ($system['last_sync'])<x-time :datetime="$system['last_sync']" />@else {{ __('Never') }} @endif</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-white/60">{{ __('Last download') }}</dt>
                        <dd class="font-medium">@if ($system['last_fetch'])<x-time :datetime="$system['last_fetch']" />@else {{ __('Never') }} @endif</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-white/60">{{ __('Failed runs (24h)') }}</dt>
                        <dd class="font-medium tabular-nums {{ $system['failed_24h'] ? 'text-red-600 dark:text-red-400' : '' }}">{{ $system['failed_24h'] }}</dd>
                    </div>
                    @if ($system['uses_queue'])
                        <div>
                            <dt class="text-gray-500 dark:text-white/60">{{ __('Jobs waiting in queue') }}</dt>
                            <dd class="font-medium tabular-nums">{{ $system['queued'] ?? '–' }}</dd>
                        </div>
                    @endif
                </dl>
                @if ($system['uses_queue'] && $system['last_fetch'] && $system['last_fetch']->lt(now()->subHours(26)))
                    <x-text size="sm" class="mt-3 text-amber-700 dark:text-amber-300">{{ __('No download in over a day. Check the cron job.') }}</x-text>
                @endif
            </x-card>
        </section>
    </div>
</x-layouts.app>
