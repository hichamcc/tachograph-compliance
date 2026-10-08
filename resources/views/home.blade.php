<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Welcome')])
    </head>
    <body class="min-h-screen bg-white antialiased text-gray-800 dark:text-white dark:bg-gray-950">
        <div class="mx-auto flex min-h-svh max-w-5xl flex-col px-6 sm:px-10">
            <header class="flex items-center justify-between py-6">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-lg bg-gray-900 dark:bg-white">
                        <x-phosphor-truck class="size-5 text-white dark:text-gray-900" aria-hidden="true" />
                    </span>
                    <span class="font-semibold">{{ __('Tachograph Compliance') }}</span>
                </a>
                @auth
                    <x-button size="sm" href="{{ route('app') }}">{{ __('Dashboard') }}</x-button>
                @else
                    <x-button size="sm" href="{{ route('login') }}">{{ __('Log in') }}</x-button>
                @endauth
            </header>

            <main class="flex flex-1 flex-col justify-center gap-12 py-12">
                <div class="max-w-2xl space-y-5">
                    <h1 class="text-4xl sm:text-5xl font-semibold leading-tight tracking-tight">
                        {{ __('Driving and rest times, checked every night.') }}
                    </h1>
                    <p class="text-lg text-gray-600 dark:text-white/70">
                        {{ __('Tachograph data from Mapon, checked against EU 561/2006 — breaks, daily and weekly limits, rests and compensation.') }}
                    </p>
                    <div>
                        @auth
                            <x-button variant="primary" href="{{ route('tachograph.drivers.index') }}">{{ __('Open drivers') }}</x-button>
                        @else
                            <x-button variant="primary" href="{{ route('login') }}">{{ __('Log in') }}</x-button>
                        @endauth
                    </div>
                </div>

                <x-tacho.day-strip :dark="false" class="max-w-3xl" />
            </main>

            <footer class="py-6 text-xs text-gray-400 dark:text-white/40">
                {{ __('Not an official legal determination.') }}
            </footer>
        </div>
    </body>
</html>
