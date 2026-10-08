@props(['dark' => true])
@php
    // Illustrative 24h tachograph day: [type, hours]
    $day = [
        ['rest', 6], ['work', 0.5], ['drive', 4.5], ['break', 0.75], ['drive', 3.5],
        ['work', 0.75], ['break', 0.5], ['drive', 1], ['rest', 6.5],
    ];
    $colors = $dark
        ? ['drive' => 'bg-white', 'work' => 'bg-amber-300', 'break' => 'bg-sky-300', 'rest' => 'bg-white/15']
        : ['drive' => 'bg-gray-900 dark:bg-white', 'work' => 'bg-amber-400 dark:bg-amber-300', 'break' => 'bg-sky-400 dark:bg-sky-300', 'rest' => 'bg-gray-200 dark:bg-white/15'];
    $muted = $dark ? 'text-white/40' : 'text-gray-400 dark:text-white/40';
    $gap = $dark ? 'border-gray-900' : 'border-white dark:border-gray-950';
@endphp
<figure {{ $attributes->class('space-y-3') }} aria-hidden="true">
    <div class="flex h-10 overflow-hidden rounded-md {{ $dark ? 'ring-1 ring-white/10' : '' }}">
        @foreach ($day as [$type, $hours])
            <div class="{{ $colors[$type] }} border-r {{ $gap }} last:border-r-0" style="width: {{ $hours / 24 * 100 }}%"></div>
        @endforeach
    </div>
    <div class="flex justify-between text-xs tabular-nums {{ $muted }}">
        @foreach (['00', '06', '12', '18', '24'] as $hour)
            <span>{{ $hour }}</span>
        @endforeach
    </div>
    <figcaption class="flex flex-wrap gap-x-5 gap-y-1 text-xs {{ $dark ? 'text-white/60' : 'text-gray-500 dark:text-white/60' }}">
        @foreach (['drive' => __('Driving'), 'work' => __('Work'), 'break' => __('Break'), 'rest' => __('Rest')] as $type => $label)
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm {{ $colors[$type] }}"></span>{{ $label }}</span>
        @endforeach
    </figcaption>
</figure>
