@use('App\Tachograph\Reporting\Format')
@use('App\Tachograph\Reporting\DayChart')
@php
    $days = DayChart::rows($report);
    $tz = $report->displayTimezone;
    $present = collect($days)->flatMap(fn ($d) => array_column($d['segments'], 'category'))->unique()->all();
    $markers = collect($days)->flatMap(fn ($d) => $d['markers']);
@endphp
@if ($days)
<section class="space-y-3">
    <x-heading size="lg" level="2">{{ __('Timeline') }}</x-heading>

    <style>
        .tacho-chart {
            --surface: #ffffff; --ink-2: #52514e; --muted: #898781; --grid: #e1e0d9; --base: #c3c2b7;
            --drive: #2a78d6; --work: #eb6834; --available: #1baf7a; --rest: #eda100; --none: #898781;
            --critical: #d03b3b; --serious: #ec835a;
            background: var(--surface); font-variant-numeric: tabular-nums;
        }
        .dark .tacho-chart {
            --surface: #1e2939; --ink-2: #c3c2b7; --grid: rgba(255,255,255,.08); --base: rgba(255,255,255,.22);
            --drive: #3987e5; --work: #d95926; --available: #199e70; --rest: #c98500;
        }
        .tacho-chart .tc-row { display: grid; grid-template-columns: 6.5rem 1fr 3.5rem; align-items: end; gap: .75rem; }
        .tacho-chart .tc-day { font-size: .8125rem; color: var(--ink-2); padding-bottom: .5rem; white-space: nowrap; }
        .tacho-chart .tc-total { font-size: .8125rem; text-align: right; padding-bottom: .5rem; }
        .tacho-chart .tc-track { position: relative; height: 2.25rem; }
        .tacho-chart .tc-plot { position: absolute; inset: 0 0 .5rem 0; border-bottom: 1px solid var(--base); }
        .tacho-chart .tc-grid { position: absolute; top: 0; bottom: .5rem; width: 1px; background: var(--grid); }
        .tacho-chart .tc-seg { position: absolute; bottom: 0; border-radius: 4px 4px 0 0; }
        .tacho-chart .tc-seg:hover { filter: brightness(1.15); }
        .tacho-chart .tc-drive { height: 100%; background: var(--drive); }
        .tacho-chart .tc-work { height: 66%; background: var(--work); }
        .tacho-chart .tc-available { height: 50%; background: var(--available); }
        .tacho-chart .tc-rest { height: 22%; background: var(--rest); }
        .tacho-chart .tc-none { height: 100%; border-radius: 0; background: repeating-linear-gradient(45deg, transparent 0 3px, color-mix(in srgb, var(--none) 45%, transparent) 3px 4px); }
        .tacho-chart .tc-mark { position: absolute; bottom: 0; height: 3px; border-radius: 2px; background: var(--critical); outline-offset: 2px; }
        .tacho-chart .tc-mark.tc-potential { background: var(--serious); }
        .tacho-chart .tc-mark::before { content: ''; position: absolute; inset: -8px 0; }
        .tacho-chart .tc-axis { display: flex; justify-content: space-between; font-size: .75rem; color: var(--muted); }
        .tacho-chart .tc-key { display: inline-flex; align-items: center; gap: .375rem; font-size: .8125rem; color: var(--ink-2); }
        .tacho-chart .tc-swatch { width: .75rem; height: .75rem; border-radius: 2px; }
        .tacho-chart .tc-line { width: 1rem; height: 3px; border-radius: 2px; }
        .tc-tip { position: fixed; z-index: 50; pointer-events: none; max-width: 18rem; padding: .375rem .625rem; border-radius: .5rem;
            font-size: .8125rem; line-height: 1.35; background: #0b0b0b; color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .tc-tip strong { display: block; font-weight: 600; }
        .tc-tip span { color: #c3c2b7; }
    </style>

    <x-card class="tacho-chart !p-4 space-y-4">
        {{-- Legend: always present (several series); identity is never colour alone. --}}
        <div class="flex flex-wrap gap-x-5 gap-y-2">
            @foreach (DayChart::CATEGORIES as $category => $label)
                @if (in_array($category, $present, true))
                    <span class="tc-key"><span class="tc-swatch tc-{{ $category }}" style="height:.75rem"></span>{{ __($label) }}</span>
                @endif
            @endforeach
            @if ($markers->where('potential', false)->isNotEmpty())
                <span class="tc-key"><span class="tc-line" style="background:var(--critical)"></span>{{ __('Violation') }}</span>
            @endif
            @if ($markers->where('potential', true)->isNotEmpty())
                <span class="tc-key"><span class="tc-line" style="background:var(--serious)"></span>{{ __('Potential violation') }}</span>
            @endif
        </div>

        <div class="space-y-1">
            <div class="tc-row">
                <span></span>
                <div class="tc-axis">@foreach (['00', '06', '12', '18', '24'] as $h)<span>{{ $h }}</span>@endforeach</div>
                <span class="tc-total" style="padding:0;color:var(--muted);font-size:.75rem">{{ __('Driving') }}</span>
            </div>

            @foreach ($days as $day)
                <div class="tc-row" role="img"
                    aria-label="{{ $day['date'] }}: {{ __('driving') }} {{ Format::hm($day['driving_hours']) }}{{ $day['markers'] ? ', '.trans_choice(':count violation|:count violations', count($day['markers'])) : '' }}">
                    <span class="tc-day">{{ $day['date'] }}</span>
                    <div class="tc-track">
                        <div class="tc-plot">
                            @foreach ([25, 50, 75] as $x)
                                <div class="tc-grid" style="left: {{ $x }}%"></div>
                            @endforeach
                            @foreach ($day['segments'] as $s)
                                <div class="tc-seg tc-{{ $s['category'] }}"
                                    style="left: calc({{ $s['left'] }}% + 1px); width: max(1px, calc({{ $s['width'] }}% - 2px))"
                                    data-tip-value="{{ Format::hm($s['hours']) }}"
                                    data-tip-label="{{ __($s['label']) }} · {{ Format::range($s['start'], $s['end'], $tz) }} · {{ __(Format::source($s['source'])) }}"></div>
                            @endforeach
                        </div>
                        @foreach ($day['markers'] as $m)
                            <div class="tc-mark {{ $m['potential'] ? 'tc-potential' : '' }}" tabindex="0"
                                style="left: {{ $m['left'] }}%; width: max(4px, {{ $m['width'] }}%)"
                                aria-label="{{ $m['potential'] ? __('Potential violation') : __('Violation') }}: {{ $m['rule'] }}, {{ $m['value'] }}"
                                data-tip-value="{{ $m['value'] }}"
                                data-tip-label="{{ $m['potential'] ? __('Potential violation') : __('Violation') }} · {{ $m['rule'] }} · {{ Format::range($m['start'], $m['end'], $tz) }}"></div>
                        @endforeach
                    </div>
                    <span class="tc-total">{{ $day['driving_hours'] > 0 ? Format::hm($day['driving_hours']) : '–' }}</span>
                </div>
            @endforeach
        </div>
    </x-card>

    <script>
        (() => {
            if (window.tachoTimelineTips) return;
            window.tachoTimelineTips = true;
            const tip = document.createElement('div');
            tip.className = 'tc-tip';
            tip.hidden = true;
            const value = document.createElement('strong');
            const label = document.createElement('span');
            tip.append(value, label);
            document.body.append(tip);

            const show = (el, x, y) => {
                value.textContent = el.dataset.tipValue;
                label.textContent = el.dataset.tipLabel;
                tip.hidden = false;
                const r = tip.getBoundingClientRect();
                tip.style.left = Math.min(x + 12, window.innerWidth - r.width - 8) + 'px';
                tip.style.top = Math.max(8, y - r.height - 12) + 'px';
            };
            const target = (e) => e.target.closest && e.target.closest('[data-tip-value]');

            document.addEventListener('pointermove', (e) => {
                const el = target(e);
                el ? show(el, e.clientX, e.clientY) : (tip.hidden = true);
            });
            document.addEventListener('focusin', (e) => {
                const el = target(e);
                if (!el) return;
                const r = el.getBoundingClientRect();
                show(el, r.left, r.top);
            });
            document.addEventListener('focusout', () => (tip.hidden = true));
        })();
    </script>
</section>
@endif
