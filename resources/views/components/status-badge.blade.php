@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match ($value) {
        'ACTIVE', 'APPROVED', 'COMPLETED', 'PUBLISHED', 'VERIFIED' => 'success',
        'BLOCKED', 'REJECTED', 'FAILED' => 'danger',
        'CHANGES_REQUESTED', 'NEEDS_VERIFICATION' => 'warning',
        'IN_APPROVAL', 'PENDING', 'PROCESSING' => 'primary',
        default => 'secondary',
    };
@endphp
<span {{ $attributes->class(['status-badge', "status-badge-{$tone}"]) }}>{{ str($value)->replace('_', ' ')->title() }}</span>
