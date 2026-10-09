@use('App\Tachograph\Reporting\Format')
<x-table>
    <x-slot:head>
        <x-table.row>
            <x-table.col>{{ __('Rule') }}</x-table.col>
            <x-table.col>{{ __('Status') }}</x-table.col>
            <x-table.col>{{ __('When') }}</x-table.col>
            <x-table.col class="text-right">{{ __('Value / limit') }}</x-table.col>
            <x-table.col>{{ __('Note') }}</x-table.col>
            <x-table.col>{{ __('Records') }}</x-table.col>
        </x-table.row>
    </x-slot:head>
    <x-slot:body>
        @foreach ($rows as $f)
            @php
                $diff = Format::difference($f);
                $evidence = collect($f['related_activity_ids'])->map(fn ($id) => $activityIndex[$id] ?? null);
                $outside = $evidence->filter(fn ($i) => $i === null)->count();
                $records = $evidence->filter(fn ($i) => $i !== null)->unique()->sort()->map(fn ($i) => $report->activities[$i]);
            @endphp
            <x-table.row x-show="shows('{{ Format::statusKey($f) }}', '{{ Format::ruleGroup($f['rule']) }}')">
                <x-table.cell class="font-medium whitespace-nowrap">{{ Format::rule($f['rule']) }}</x-table.cell>
                <x-table.cell class="whitespace-nowrap"><x-tacho.status :status="$f['status']" :certainty="$f['certainty']" :label="Format::findingLabel($f, $report->displayTimezone)" /></x-table.cell>
                <x-table.cell class="whitespace-nowrap tabular-nums" title="{{ Format::utc($f['period_start']) }} → {{ Format::utc($f['period_end']) }}">{{ Format::range($f['period_start'], $f['period_end'], $report->displayTimezone) }}</x-table.cell>
                <x-table.cell class="text-right tabular-nums whitespace-nowrap">
                    {{ Format::value($f) }}
                    @if ($diff)<span class="ms-1 font-medium text-red-600 dark:text-red-400">{{ $diff }}</span>@endif
                </x-table.cell>
                <x-table.cell class="min-w-48 text-gray-600 dark:text-white/70">{{ Format::note($f, $report->displayTimezone) }}</x-table.cell>
                <x-table.cell class="text-sm">
                    @if ($records->isNotEmpty() || $outside)
                        <details>
                            <summary class="cursor-pointer whitespace-nowrap text-gray-600 dark:text-white/70">{{ trans_choice(':count record|:count records', $records->count() + ($outside ? 1 : 0)) }}</summary>
                            <ul class="mt-2 space-y-1 whitespace-nowrap tabular-nums">
                                @foreach ($records as $a)
                                    <li>
                                        <span class="font-medium">{{ Format::activity($a['type']) }}</span>
                                        {{ Format::range($a['start'], $a['end'], $report->displayTimezone) }}
                                        · {{ Format::hm($a['duration_hours']) }}
                                        <span class="text-gray-500">· {{ __(Format::source($a['source'])) }}</span>
                                    </li>
                                @endforeach
                                @if ($outside)
                                    <li class="text-gray-500">{{ __('+ data before or after this period') }}</li>
                                @endif
                            </ul>
                        </details>
                    @endif
                </x-table.cell>
            </x-table.row>
        @endforeach
    </x-slot:body>
</x-table>
