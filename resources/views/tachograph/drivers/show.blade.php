<x-layouts.app :title="$driver->label().' | '.__('Tachograph')">
    <x-headbar :title="$driver->label()" :subtitle="__('ID :id', ['id' => $driver->external_id]).($driver->isMapon() ? '' : ' · '.__('imported'))">
        <x-button href="{{ route('tachograph.drivers.index') }}" size="sm">{{ __('All drivers') }}</x-button>
    </x-headbar>

    <div class="mt-6 space-y-8">
        @include('tachograph.partials.flash')

        <x-section>
            <x-heading level="2">{{ __('Run check') }}</x-heading>
            <x-card>
                <x-form method="post" action="{{ route('tachograph.runs.store', $driver) }}" class="grid sm:grid-cols-3 gap-4 items-end">
                    <x-input type="date" name="start" :value="old('start', $defaultStart)" :label="__('First day')" required max="{{ now()->toDateString() }}" />
                    <x-input type="date" name="end" :value="old('end', $defaultEnd)" :label="__('Last day')" required max="{{ now()->toDateString() }}" />
                    <x-button variant="primary">{{ __('Run check') }}</x-button>
                </x-form>
                @if ($dataFrom)
                    <x-text size="sm" class="mt-3">{{ __('Data: :from – :until', ['from' => \Illuminate\Support\Carbon::parse($dataFrom, 'UTC')->tz(config('tachograph.display_timezone'))->format('j M'), 'until' => \Illuminate\Support\Carbon::parse($dataUntil, 'UTC')->tz(config('tachograph.display_timezone'))->format('j M Y')]) }}</x-text>
                @endif
            </x-card>
        </x-section>

        <section class="space-y-3">
            <x-heading size="lg" level="2">{{ __('Reports') }}</x-heading>
            @if ($reports->isNotEmpty())
                <x-table>
                    <x-slot:head>
                        <x-table.row>
                            <x-table.col>{{ __('Period') }}</x-table.col>
                            <x-table.col class="text-right">{{ __('Violations') }}</x-table.col>
                            <x-table.col class="text-right">{{ __('Potential') }}</x-table.col>
                            <x-table.col class="text-right">{{ __('Not enough data') }}</x-table.col>
                            <x-table.col>{{ __('Generated') }}</x-table.col>
                            <x-table.col class="text-right">{{ __('Download') }}</x-table.col>
                            <x-table.col class="text-right"></x-table.col>
                        </x-table.row>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($reports as $report)
                            @php($run = $report['latest'])
                            <x-table.row>
                                <x-table.cell>
                                    <x-link href="{{ route('tachograph.reports.show', $run) }}" class="font-semibold">{{ $report['label'] }}</x-link>
                                    <div class="text-xs text-gray-500 tabular-nums">{{ $report['dates'] }}</div>
                                </x-table.cell>
                                <x-table.cell class="text-right tabular-nums">
                                    <x-badge size="sm" :color="$run->confirmed_count ? 'red' : 'green'">{{ $run->confirmed_count }}</x-badge>
                                </x-table.cell>
                                <x-table.cell class="text-right tabular-nums">
                                    @if ($run->potential_count)<x-badge size="sm" color="orange">{{ $run->potential_count }}</x-badge>@else – @endif
                                </x-table.cell>
                                <x-table.cell class="text-right tabular-nums">
                                    @if ($run->incomplete_count)<x-badge size="sm" color="amber">{{ $run->incomplete_count }}</x-badge>@else – @endif
                                </x-table.cell>
                                <x-table.cell class="text-sm">
                                    <x-time :datetime="$run->created_at" />
                                    @if ($report['older']->isNotEmpty())
                                        <details class="mt-1 text-xs text-gray-500">
                                            <summary class="cursor-pointer">{{ trans_choice(':count earlier version|:count earlier versions', $report['older']->count()) }}</summary>
                                            <ul class="mt-1 space-y-0.5">
                                                @foreach ($report['older'] as $older)
                                                    <li><x-link href="{{ route('tachograph.reports.show', $older) }}"><x-time :datetime="$older->created_at" /></x-link> · {{ trans_choice(':count violation|:count violations', $older->confirmed_count) }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </x-table.cell>
                                <x-table.cell class="text-right whitespace-nowrap text-sm">
                                    <x-link href="{{ route('tachograph.reports.export', [$run, 'csv', 'findings']) }}">CSV</x-link> ·
                                    <x-link href="{{ route('tachograph.reports.export', [$run, 'json']) }}">JSON</x-link> ·
                                    <x-link href="{{ route('tachograph.reports.export', [$run, 'html']) }}">HTML</x-link>
                                </x-table.cell>
                                <x-table.cell class="text-right">
                                    <x-button size="sm" href="{{ route('tachograph.reports.show', $run) }}">{{ __('View') }}</x-button>
                                </x-table.cell>
                            </x-table.row>
                        @endforeach
                    </x-slot:body>
                </x-table>
            @else
                <x-text>{{ __('No reports yet.') }}</x-text>
            @endif
        </section>

        <details class="space-y-3">
            <summary class="cursor-pointer text-base font-medium text-gray-800 dark:text-white">{{ __('Run history') }} ({{ $runs->count() }})</summary>
            <div class="mt-3">
            @if ($runs->isNotEmpty())
                <x-table>
                    <x-slot:head>
                        <x-table.row>
                            <x-table.col>{{ __('Started') }}</x-table.col>
                            <x-table.col>{{ __('Type') }}</x-table.col>
                            <x-table.col>{{ __('Period') }}</x-table.col>
                            <x-table.col>{{ __('Status') }}</x-table.col>
                            <x-table.col class="text-right">{{ __('Records') }}</x-table.col>
                            <x-table.col class="text-right">{{ __('Violations') }}</x-table.col>
                            <x-table.col class="text-right"></x-table.col>
                        </x-table.row>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($runs as $run)
                            <x-table.row>
                                <x-table.cell><x-time :datetime="$run->created_at" /></x-table.cell>
                                <x-table.cell>{{ __(\App\Tachograph\Reporting\Format::runType($run->type->value)) }}</x-table.cell>
                                <x-table.cell class="tabular-nums whitespace-nowrap">
                                    {{ $run->period_start?->format('Y-m-d') ?? '–' }} – {{ $run->period_end?->subSecond()->format('Y-m-d') ?? '–' }}
                                </x-table.cell>
                                <x-table.cell>@include('tachograph.partials.run-status')</x-table.cell>
                                <x-table.cell class="text-right tabular-nums">{{ $run->records_processed }}</x-table.cell>
                                <x-table.cell class="text-right tabular-nums">{{ $run->type->value === 'evaluate' && $run->status->value === 'done' ? $run->violations_count : '–' }}</x-table.cell>
                                <x-table.cell class="text-right">
                                    @if ($run->type->value === 'evaluate' && $run->status->value === 'done')
                                        <x-link href="{{ route('tachograph.reports.show', $run) }}">{{ __('Report') }}</x-link>
                                    @else
                                        <x-link href="{{ route('tachograph.runs.show', $run) }}">{{ __('Details') }}</x-link>
                                    @endif
                                </x-table.cell>
                            </x-table.row>
                        @endforeach
                    </x-slot:body>
                </x-table>
            @else
                <x-text>{{ __('No runs yet.') }}</x-text>
            @endif
            </div>
        </details>
    </div>
</x-layouts.app>
