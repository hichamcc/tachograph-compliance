@use('App\Tachograph\Reporting\Format')
@php
    $violations = $report->findingsWithStatus('VIOLATION');
    $attention = $report->findingsWithStatus('INCOMPLETE_DATA', 'WARNING');
    $all = [...$violations, ...$attention];
    $keys = fn (array $rows) => array_map(fn ($f) => [Format::statusKey($f), Format::ruleGroup($f['rule'])], $rows);
    $statusCounts = array_count_values(array_map(fn ($f) => Format::statusKey($f), $all));
    $groups = array_unique(array_map(fn ($f) => Format::ruleGroup($f['rule']), $all));
    $filters = ($filters ?? false) && $all;
@endphp
<div class="space-y-10"
    @if ($filters)
        x-data="{
            status: new URLSearchParams(location.search).get('status') || 'all',
            rule: new URLSearchParams(location.search).get('rule') || 'all',
            shows(s, r) { return (this.status === 'all' || this.status === s) && (this.rule === 'all' || this.rule === r) },
            any(rows) { return rows.some(([s, r]) => this.shows(s, r)) },
            sync() {
                const url = new URL(location);
                for (const key of ['status', 'rule']) this[key] === 'all' ? url.searchParams.delete(key) : url.searchParams.set(key, this[key]);
                history.replaceState(null, '', url);
            },
        }"
        x-init="$watch('status', () => sync()); $watch('rule', () => sync())"
    @else
        x-data="{ shows: () => true, any: () => true }"
    @endif
>
    @if ($filters)
        <div class="flex flex-wrap items-center gap-3" role="group" aria-label="{{ __('Filter findings') }}">
            <x-button.group>
                @foreach (['all' => __('All'), 'violation' => __('Violations'), 'potential' => __('Potential'), 'incomplete' => __('Not enough data'), 'warning' => __('Warnings')] as $key => $label)
                    @if ($key === 'all' || ($statusCounts[$key] ?? 0))
                        <x-button size="sm" type="button" x-on:click="status = '{{ $key }}'" x-bind:aria-pressed="status === '{{ $key }}'">
                            {{ $label }}@if ($key !== 'all')<span class="tabular-nums opacity-60">{{ $statusCounts[$key] }}</span>@endif
                        </x-button>
                    @endif
                @endforeach
            </x-button.group>
            @if (count($groups) > 1)
                <label class="sr-only" for="rule-filter">{{ __('Rule') }}</label>
                <select id="rule-filter" x-model="rule" class="h-8 rounded-md border border-gray-200 bg-white px-2 pe-8 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="all">{{ __('All rules') }}</option>
                    @foreach (Format::RULE_GROUPS as $group => $label)
                        @if (in_array($group, $groups, true))
                            <option value="{{ $group }}">{{ __($label) }}</option>
                        @endif
                    @endforeach
                </select>
            @endif
            <x-link href="#" x-show="status !== 'all' || rule !== 'all'" x-on:click.prevent="status = 'all'; rule = 'all'" class="text-sm">{{ __('Clear') }}</x-link>
        </div>
    @endif

    <section class="space-y-3">
        <x-heading size="lg" level="2">{{ __('Violations') }}</x-heading>
        @if ($violations)
            <div x-show="any(@js($keys($violations)))">
                @include('tachograph.reports.partials.findings-table', ['rows' => $violations])
            </div>
            <p class="text-sm text-gray-600 dark:text-white/70" x-show="! any(@js($keys($violations)))" x-cloak>{{ __('None match the filter.') }}</p>
        @else
            <x-text>{{ __('No violations.') }}</x-text>
        @endif
    </section>

    @if ($attention)
        <section class="space-y-3" x-show="any(@js($keys($attention)))">
            <x-heading size="lg" level="2">{{ __('Warnings & missing data') }}</x-heading>
            @include('tachograph.reports.partials.findings-table', ['rows' => $attention])
        </section>
    @endif
</div>
