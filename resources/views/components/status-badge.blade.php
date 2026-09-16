@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match ($value) {
        'ACTIVE', 'APPROVED', 'COMPLETED', 'PUBLISHED' => 'success',
        'BLOCKED', 'REJECTED' => 'danger',
        'CHANGES_REQUESTED' => 'warning',
        'IN_APPROVAL', 'PENDING' => 'primary',
        default => 'secondary',
    };
@endphp
<span {{ $attributes->class(['status-badge', "status-badge-{$tone}"]) }}>{{ str($value)->replace('_', ' ')->title() }}</span>
