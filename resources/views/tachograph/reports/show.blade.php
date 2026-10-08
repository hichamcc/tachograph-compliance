@use('App\Tachograph\Reporting\Format')
<x-layouts.app :title="__('Tachograph report')">
    <x-headbar :title="__('Tachograph report')"
        :subtitle="($run->driver?->label() ?? $report->driver['id']).' · '.Format::local($report->period['start'], $report->displayTimezone, 'j M').' – '.Format::local((new DateTimeImmutable($report->period['end']))->modify('-1 second')->format('c'), $report->displayTimezone, 'j M Y')">
        <x-button size="sm" href="{{ route('tachograph.reports.export', [$run, 'json']) }}" before="phosphor-download-simple">JSON</x-button>
        <x-button size="sm" href="{{ route('tachograph.reports.export', [$run, 'csv', 'findings']) }}" before="phosphor-download-simple">{{ __('Findings CSV') }}</x-button>
        <x-button size="sm" href="{{ route('tachograph.reports.export', [$run, 'csv', 'daily']) }}">{{ __('Shifts CSV') }}</x-button>
        <x-button size="sm" href="{{ route('tachograph.reports.export', [$run, 'csv', 'weekly']) }}">{{ __('Weeks CSV') }}</x-button>
        <x-button size="sm" href="{{ route('tachograph.reports.export', [$run, 'csv', 'activities']) }}">{{ __('Activities CSV') }}</x-button>
        <x-button size="sm" href="{{ route('tachograph.reports.export', [$run, 'html']) }}">HTML</x-button>
    </x-headbar>

    @if ($weeks && (($weeks['previous']['run'] ?? null) || ($weeks['next']['run'] ?? null)))
        <nav class="mt-4 flex items-center justify-between gap-3" aria-label="{{ __('Other weeks') }}">
            @foreach (['previous' => $weeks['previous'], 'next' => $weeks['next']] as $direction => $week)
                @if ($week && $week['run'])
                    <x-button size="sm" href="{{ route('tachograph.reports.show', $week['run']) }}"
                        :before="$direction === 'previous' ? 'phosphor-caret-left' : ''" :after="$direction === 'next' ? 'phosphor-caret-right' : ''">{{ $week['label'] }}</x-button>
                @else
                    <span></span>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="mt-6">
        @include('tachograph.reports.partials.body', ['filters' => true])
    </div>
</x-layouts.app>
