@use('App\Tachograph\Reporting\Format')
<section class="space-y-3">
    <x-heading size="lg" level="2">{{ __('Weeks') }}</x-heading>
    <x-table>
        <x-slot:head>
            <x-table.row>
                <x-table.col>{{ __('Week') }}</x-table.col>
                <x-table.col class="text-right">{{ __('Driving') }}</x-table.col>
                <x-table.col class="text-right">{{ __('2 weeks') }}</x-table.col>
                <x-table.col class="text-right">{{ __('Extended days') }}</x-table.col>
                <x-table.col class="text-right">{{ __('Reduced rests') }}</x-table.col>
                <x-table.col>{{ __('Weekly rest') }}</x-table.col>
                <x-table.col>{{ __('Compensation') }}</x-table.col>
                <x-table.col>{{ __('Status') }}</x-table.col>
            </x-table.row>
        </x-slot:head>
        <x-slot:body>
            @foreach ($report->weekly as $w)
                <x-table.row>
                    <x-table.cell class="whitespace-nowrap">
                        <span class="font-medium">{{ $w['week'] }}</span>
                        <span class="text-xs text-gray-500"><x-tacho.time :value="$w['start']" :tz="$report->displayTimezone" format="j M" /></span>
                    </x-table.cell>
                    <x-table.cell class="text-right tabular-nums">{{ Format::hm($w['driving_hours']) }} / {{ $w['max_driving_hours'] }}h00</x-table.cell>
                    <x-table.cell class="text-right tabular-nums">{{ Format::hm($w['two_week_driving_hours']) }}</x-table.cell>
                    <x-table.cell class="text-right tabular-nums">{{ $w['extended_days_used'] }} / {{ $w['extended_days_allowed'] }}</x-table.cell>
                    <x-table.cell class="text-right tabular-nums">{{ $w['reduced_daily_rests'] }}</x-table.cell>
                    <x-table.cell>
                        @forelse ($w['weekly_rests'] as $r)
                            <div class="whitespace-nowrap">{{ __(ucfirst($r['type'])) }} {{ Format::hm($r['hours']) }} · <x-tacho.time :value="$r['start']" :tz="$report->displayTimezone" /></div>
                        @empty
                            –
                        @endforelse
                    </x-table.cell>
                    <x-table.cell>
                        @forelse ($w['compensation'] as $c)
                            <div class="whitespace-nowrap">{{ Format::hm($c['owed_hours']) }} · {{ $c['status'] === 'PENDING' ? __('due') : strtolower($c['status']) }} <x-tacho.time :value="$c['due_by']" :tz="$report->displayTimezone" format="D j M H:i" /></div>
                        @empty
                            –
                        @endforelse
                    </x-table.cell>
                    <x-table.cell><x-tacho.status :status="$w['status']" /></x-table.cell>
                </x-table.row>
            @endforeach
        </x-slot:body>
    </x-table>
</section>
