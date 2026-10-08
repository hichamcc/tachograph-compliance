@php($issues = array_values(array_filter($report->dataQuality, fn ($i) => $i['severity'] !== 'INFO')))
@if ($issues)
    <section class="space-y-3">
        <x-heading size="lg" level="2">{{ __('Data problems') }}</x-heading>
        <x-table>
            <x-slot:head>
                <x-table.row>
                    <x-table.col>{{ __('Problem') }}</x-table.col>
                    <x-table.col>{{ __('When') }}</x-table.col>
                    <x-table.col>{{ __('Details') }}</x-table.col>
                </x-table.row>
            </x-slot:head>
            <x-slot:body>
                @foreach ($issues as $i)
                    <x-table.row>
                        <x-table.cell class="whitespace-nowrap">
                            <x-badge size="sm" :color="$i['severity'] === 'ERROR' ? 'red' : 'amber'">{{ ucfirst(strtolower(str_replace('_', ' ', $i['type']))) }}</x-badge>
                        </x-table.cell>
                        <x-table.cell class="whitespace-nowrap tabular-nums">
                            @if ($i['period_start'] && $i['period_end'])
                                {{ \App\Tachograph\Reporting\Format::range($i['period_start'], $i['period_end'], $report->displayTimezone) }}
                            @elseif ($i['period_start'])
                                <x-tacho.time :value="$i['period_start']" :tz="$report->displayTimezone" />
                            @else
                                –
                            @endif
                        </x-table.cell>
                        <x-table.cell>{{ $i['message'] }}</x-table.cell>
                    </x-table.row>
                @endforeach
            </x-slot:body>
        </x-table>
    </section>
@endif
