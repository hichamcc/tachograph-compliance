@php($color = match ($run->status->value) { 'done' => 'green', 'failed' => 'red', 'running' => 'blue', default => 'gray' })
<x-badge size="sm" :color="$color">{{ __(ucfirst($run->status->value)) }}</x-badge>
