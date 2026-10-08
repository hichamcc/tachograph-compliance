<x-layouts.app :title="__('Run | Tachograph')">
    <x-headbar :title="__(\App\Tachograph\Reporting\Format::runType($run->type->value))" :subtitle="$run->driver?->label()">
        @if ($run->driver)
            <x-button href="{{ route('tachograph.drivers.show', $run->driver) }}" size="sm">{{ __('Back to driver') }}</x-button>
        @endif
    </x-headbar>

    <div id="run-status" class="mt-6 space-y-4"
        @unless ($run->status->isFinished())
            x-data x-init="setTimeout(() => $ajax('{{ route('tachograph.runs.show', $run) }}', { target: 'run-status' }), 3000)"
        @endunless
    >
        <x-card class="space-y-3">
            <div class="flex items-center gap-3">
                @include('tachograph.partials.run-status')
                <x-text>
                    @switch ($run->status->value)
                        @case('pending')
                            {{ $batch && ! $batch->finished() ? __('Downloading from Mapon…') : __('Waiting in the queue…') }}
                            @break
                        @case('running')
                            {{ __('Processing…') }}
                            @break
                        @case('done')
                            {{ __('Finished.') }}
                            @break
                        @default
                            {{ $run->error_message ?? __('Failed.') }}
                    @endswitch
                </x-text>
            </div>

            @if ($batch && ! $run->status->isFinished())
                <div>
                    <div class="h-2 w-full rounded-full bg-gray-100 dark:bg-white/10 overflow-hidden">
                        <div class="h-2 bg-[var(--color-accent)]" style="width: {{ $batch->progress() }}%"></div>
                    </div>
                    <x-text size="sm" class="mt-1">{{ __(':done of :total downloaded', ['done' => $batch->processedJobs(), 'total' => $batch->totalJobs]) }}</x-text>
                </div>
            @endif

            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div><dt class="text-gray-500">{{ __('Period') }}</dt><dd class="tabular-nums">{{ $run->period_start?->format('Y-m-d') ?? '–' }} – {{ $run->period_end?->subSecond()->format('Y-m-d') ?? '–' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Records processed') }}</dt><dd class="tabular-nums">{{ $run->records_processed }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Findings') }}</dt><dd class="tabular-nums">{{ $run->findings_count }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Data problems') }}</dt><dd class="tabular-nums">{{ ($issues['WARNING'] ?? 0) + ($issues['ERROR'] ?? 0) }}</dd></div>
            </dl>

            @if ($run->status->value === 'done' && $run->type->value === 'evaluate')
                <x-button variant="primary" href="{{ route('tachograph.reports.show', $run) }}" before="phosphor-file-text">{{ __('View report') }}</x-button>
            @endif

        </x-card>
    </div>
</x-layouts.app>
