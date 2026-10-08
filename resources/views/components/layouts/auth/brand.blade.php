<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased text-gray-800 dark:text-white dark:bg-gray-950">
        <div class="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,4fr)]">
            <aside class="relative hidden lg:flex flex-col justify-between overflow-hidden bg-gray-900 p-12 text-white">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <span class="flex size-10 items-center justify-center rounded-lg bg-white">
                        <x-phosphor-truck class="size-6 text-gray-900" aria-hidden="true" />
                    </span>
                    <span class="leading-tight">
                        <span class="block font-semibold">{{ __('Tachograph') }}</span>
                        <span class="block text-sm text-white/60">{{ __('Compliance') }}</span>
                    </span>
                </a>

                <div class="max-w-lg space-y-8">
                    <p class="text-3xl font-semibold leading-snug tracking-tight">
                        {{ __('Every driving hour, break and rest — checked against EU 561/2006.') }}
                    </p>

                    <x-tacho.day-strip />
                </div>

                <p class="text-xs text-white/40">{{ __('Data from Mapon') }}</p>
            </aside>

            <main class="flex items-center justify-center p-6 sm:p-12">
                <div class="w-full max-w-sm space-y-8">
                    <a href="{{ route('home') }}" class="flex items-center gap-3 lg:hidden">
                        <span class="flex size-10 items-center justify-center rounded-lg bg-gray-900 dark:bg-white">
                            <x-phosphor-truck class="size-6 text-white dark:text-gray-900" aria-hidden="true" />
                        </span>
                        <span class="font-semibold">{{ __('Tachograph Compliance') }}</span>
                    </a>
                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>
