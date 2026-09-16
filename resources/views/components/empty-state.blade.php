@props(['title', 'description'])

<div {{ $attributes->class(['empty-state']) }}>
    <span class="empty-state-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
            <path d="M5 7.5h14M7.5 4h9l1 3.5v12h-11v-12L7.5 4Z" />
            <path d="M9.5 11.5h5M9.5 15h5" />
        </svg>
    </span>
    <strong>{{ $title }}</strong>
    <p>{{ $description }}</p>
    @isset($action)
        <div class="mt-3">{{ $action }}</div>
    @endisset
</div>
