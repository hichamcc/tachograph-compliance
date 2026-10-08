@props(['route' => null, 'name' => null])

<?php if ($name): ?>
    @php
        $param = request('sort', '');
        $dir = $param === "{$name}__asc" ? 'ascending' : 'descending';
    @endphp
    <th {{ $attributes->merge([
        'aria-sort' => Str::startsWith($param, $name) ? $dir : null,
        'class' => 'py-1.5 px-3 [:where(&)]:text-left text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white'
    ]) }}>
        <a href="{{ route($route, array_merge(request()->except('page'), ['sort' => $name.'__'.($dir === 'ascending' ? 'desc' : 'asc')])) }}" class="inline-flex items-center gap-1">
            {{ $slot }}
            @if (Str::startsWith($param, $name))
                <span aria-hidden="true" class="text-gray-400">{{ $dir === 'ascending' ? '↑' : '↓' }}</span>
            @endif
        </a>
    </th>
<?php else: ?>
    <th {{ $attributes->class('py-1.5 px-3 [:where(&)]:text-left text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white') }}>
        {{ $slot }}
    </th>
<?php endif; ?>
