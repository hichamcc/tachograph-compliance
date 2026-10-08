@use('App\Tachograph\Reporting\Format')
<section class="space-y-3">
    <x-heading size="lg" level="2">{{ __('Shifts') }}</x-heading>
    @if ($report->daily)
        <x-table>
            <x-slot:head>
                <x-table.row>
                    <x-table.col>{{ __('Shift') }}</x-table.col>
                    <x-table.col class="text-right">{{ __('Driving') }}</x-table.col>
                    <x-table.col class="text-right">{{ __('Work') }}</x-table.col>
                    <x-table.col class="text-right">{{ __('Breaks') }}</x-table.col>
                    <x-table.col class="text-right">{{ __('No data') }}</x-table.col>
                    <x-table.col class="text-right">{{ __('Rest after') }}</x-table.col>
                    <x-table.col>{{ __('Daily driving') }}</x-table.col>
                    <x-table.col>{{ __('Breaks') }}</x-table.col>
                    <x-table.col>{{ __('Daily rest') }}</x-table.col>
                </x-table.row>
            </x-slot:head>
            <x-slot:body>
                @foreach ($report->daily as $d)
                    <x-table.row>
                        <x-table.cell class="whitespace-nowrap tabular-nums" title="{{ Format::utc($d['shift_start']) }} → {{ Format::utc($d['shift_end']) }}">{{ Format::range($d['shift_start'], $d['shift_end'], $report->displayTimezone) }}</x-table.cell>
                        <x-table.cell class="text-right tabular-nums">{{ Format::hm($d['driving_hours']) }}</x-table.cell>
                        <x-table.cell class="text-right tabular-nums">{{ Format::hm($d['work_hours'] + $d['availability_hours']) }}</x-table.cell>
                        <x-table.cell class="text-right tabular-nums">{{ Format::hm($d['break_hours']) }}</x-table.cell>
                        <x-table.cell class="text-right tabular-nums">{{ $d['unknown_hours'] > 0 ? Format::hm($d['unknown_hours']) : '–' }}</x-table.cell>
                        <x-table.cell class="text-right tabular-nums">{{ Format::hm($d['following_rest_hours']) }}</x-table.cell>
                        <x-table.cell><x-tacho.status :status="$d['daily_driving_status']" :label="$d['daily_driving_status'] === 'WARNING' ? __('Extended day') : null" /></x-table.cell>
                        <x-table.cell><x-tacho.status :status="$d['break_status']" /></x-table.cell>
                        <x-table.cell class="whitespace-nowrap">
                            <x-tacho.status :status="$d['daily_rest_status']" />
                            @if ($d['daily_rest_type'] && $d['daily_rest_type'] !== 'insufficient')<span class="text-xs text-gray-500">{{ __($d['daily_rest_type']) }}</span>@endif
                        </x-table.cell>
                    </x-table.row>
                @endforeach
            </x-slot:body>
        </x-table>
    @else
        <x-text>{{ __('No shifts in this period.') }}</x-text>
    @endif
</section>
