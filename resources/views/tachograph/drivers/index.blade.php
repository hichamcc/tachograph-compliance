<x-layouts.app :title="__('Drivers | Tachograph')">
    <x-headbar :title="__('Drivers')">
        <x-form method="post" action="{{ route('tachograph.drivers.sync') }}">
            <x-button before="phosphor-arrows-clockwise">{{ __('Sync from Mapon') }}</x-button>
        </x-form>
    </x-headbar>

    <div class="mt-6 space-y-6">
        @include('tachograph.partials.flash')

        <form x-target="drivers" x-on:input.debounce="$el.requestSubmit()" class="flex gap-3">
            <x-label for="q" :value="__('Search')" class="sr-only" />
            <x-input name="q" :value="$q" placeholder="{{ __('Search by name or driver ID') }}" autocomplete="off" />
            @if (request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
            <x-button x-show="false">{{ __('Search') }}</x-button>
        </form>

        <div id="drivers">
            @if ($drivers->isNotEmpty())
                <x-table>
                    <x-slot:head>
                        <x-table.row>
                            <x-table.col>{{ __('Driver') }}</x-table.col>
                            <x-table.col>{{ __('ID') }}</x-table.col>
                            <x-table.col>{{ __('Data until') }}</x-table.col>
                            <x-table.col name="last_check" route="tachograph.drivers.index">{{ __('Last check') }}</x-table.col>
                            <x-table.col name="violations" route="tachograph.drivers.index" class="text-right">{{ __('Violations') }}</x-table.col>
                        </x-table.row>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($drivers as $driver)
                            @php($run = $runs[$driver->latest_run_id] ?? null)
                            <x-table.row>
                                <x-table.cell>
                                    <x-link href="{{ route('tachograph.drivers.show', $driver) }}" class="font-semibold">{{ $driver->label() }}</x-link>
                                    @unless ($driver->is_active)<x-badge size="sm" class="ms-2">{{ __('Inactive') }}</x-badge>@endunless
                                    @unless ($driver->isMapon())<x-badge size="sm" color="violet" class="ms-2">{{ __('Imported') }}</x-badge>@endunless
                                </x-table.cell>
                                <x-table.cell class="tabular-nums text-gray-500">{{ $driver->external_id }}</x-table.cell>
                                <x-table.cell>
                                    @if ($driver->last_data_at)
                                        <x-time :datetime="\Illuminate\Support\Carbon::parse($driver->last_data_at, 'UTC')" />
                                    @else
                                        <span class="text-gray-400">–</span>
                                    @endif
                                </x-table.cell>
                                <x-table.cell>
                                    @if ($run)
                                        <a href="{{ route($run->status->value === 'done' ? 'tachograph.reports.show' : 'tachograph.runs.show', $run) }}" class="inline-flex items-center gap-2">
                                            @include('tachograph.partials.run-status', ['run' => $run])
                                            <x-time :datetime="$run->created_at" class="text-sm text-gray-500" />
                                        </a>
                                    @else
                                        <span class="text-gray-400">{{ __('Never') }}</span>
                                    @endif
                                </x-table.cell>
                                <x-table.cell class="text-right tabular-nums">
                                    @if ($run && $run->status->value === 'done')
                                        <x-badge size="sm" :color="$run->violations_count ? 'red' : 'green'">{{ $run->violations_count }}</x-badge>
                                    @else
                                        <span class="text-gray-400">–</span>
                                    @endif
                                </x-table.cell>
                            </x-table.row>
                        @endforeach
                    </x-slot:body>
                </x-table>
                <x-pagination class="mt-1" :paginator="$drivers" />
            @else
                <x-text>{{ $q !== '' ? __('No drivers match your search.') : __('No drivers yet.') }}</x-text>
            @endif
        </div>

        @if ($allowImport)
            <x-section>
                <x-heading level="2">{{ __('Import JSON file') }}</x-heading>
                <x-card>
                    <x-form method="post" action="{{ route('tachograph.import') }}" upload class="grid sm:grid-cols-4 gap-4 items-end">
                        <x-input type="file" name="file" accept=".json,application/json" :label="__('File')" required />
                        <x-select name="format" :label="__('Format')" :options="['local' => __('Local fixture format'), 'mapon' => __('Raw Mapon response')]" />
                        <x-input name="driver_id" :label="__('Driver ID (Mapon format)')" placeholder="test_driver_01" />
                        <x-button before="phosphor-upload-simple">{{ __('Import') }}</x-button>
                    </x-form>
                </x-card>
            </x-section>
        @endif
    </div>
</x-layouts.app>
