@php
    // source event ID → activity row, for the evidence lists
    $activityIndex = [];
    foreach ($report->activities as $i => $activity) {
        foreach ($activity['source_event_ids'] as $id) {
            $activityIndex[$id] = $i;
        }
    }
@endphp
<div class="space-y-10 tacho-report">
    @include('tachograph.reports.partials.summary')
    @if (config('tachograph.show_timeline'))
        @include('tachograph.reports.partials.timeline')
    @endif
    @include('tachograph.reports.partials.findings')
    @include('tachograph.reports.partials.daily')
    @include('tachograph.reports.partials.weekly')
    @include('tachograph.reports.partials.data-quality')

    <x-text size="sm" class="border-t border-gray-200 dark:border-white/10 pt-4 tacho-disclaimer">
        {{ __('Not an official legal determination.') }}
        {{ __('Times in :tz, hover for UTC.', ['tz' => $report->displayTimezone]) }}
    </x-text>
</div>
