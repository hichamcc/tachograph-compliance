<x-layouts.auth.brand :title="__('Log in')">
<div class="space-y-6">
    <div>
        <x-heading size="xl" level="1">{{ __('Log in') }}</x-heading>
        <x-subheading class="mt-1">{{ __('Use your work email.') }}</x-subheading>
    </div>

    <x-auth-session-status :status="session('status')" />

    <x-form method="post" :action="route('login.store')" class="space-y-6">
        <x-input
            type="email"
            :label="__('Email address')"
            name="email"
            required
            autofocus
            autocomplete="email"
        />

        <div class="relative">
            <x-input
                type="password"
                :label="__('Password')"
                name="password"
                required
                autocomplete="current-password"
            />

            @if (Route::has('password.request'))
                <x-link class="absolute right-0 top-0 text-sm" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </x-link>
            @endif
        </div>

        <x-checkbox name="remember" :label="__('Remember me')" />

        <x-button variant="primary" class="w-full">{{ __('Log in') }}</x-button>
    </x-form>

</div>
</x-layouts.auth.brand>
