@props(['title', 'description' => null])

<header {{ $attributes->class(['page-header']) }}>
    <div class="page-header-copy">
        <h1>{{ $title }}</h1>
        @if ($description)
            <p>{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="page-header-actions">{{ $actions }}</div>
    @endisset
</header>
