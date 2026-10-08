@props(['status', 'certainty' => null, 'label' => null])
@use('App\Tachograph\Reporting\Format')
<x-badge size="sm" :color="$status === 'VIOLATION' && $certainty === 'POTENTIAL' ? 'orange' : Format::statusColor($status)" {{ $attributes->merge(['data-status' => $status]) }}>{{ $label ?? Format::statusLabel($status) }}</x-badge>
