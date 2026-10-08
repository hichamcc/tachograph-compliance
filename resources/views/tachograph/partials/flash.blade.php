@if (session('status'))
    <div role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-400/30 dark:bg-green-400/10 dark:text-green-200">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-400/30 dark:bg-red-400/10 dark:text-red-200">{{ session('error') }}</div>
@endif
