<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · OpsFlow</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@auth
    <a class="visually-hidden-focusable skip-link" href="#main-content">Skip to content</a>

    <aside class="app-sidebar-desktop d-none d-lg-flex">
        <a class="app-brand" href="{{ route('dashboard') }}">
            <span class="app-brand-mark" aria-hidden="true">O</span>
            <span><strong>OpsFlow</strong><small>Spend operations</small></span>
        </a>
        @include('partials.app-navigation')
        <div class="app-sidebar-footer">
            <span class="app-user-avatar" aria-hidden="true">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</span>
            <span class="min-w-0"><strong class="text-truncate d-block">{{ auth()->user()->name }}</strong><small>{{ str(auth()->user()->role->value)->lower()->title() }}</small></span>
        </div>
    </aside>

    <div class="app-content">
        <header class="app-topbar">
            <button class="btn app-menu-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appNavigation" aria-controls="appNavigation" aria-label="Open navigation">
                <span></span><span></span><span></span>
            </button>
            <a class="app-mobile-brand d-lg-none" href="{{ route('dashboard') }}">OpsFlow</a>
            <span class="app-topbar-context d-none d-lg-inline">Operations workspace</span>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <a class="topbar-notifications" href="{{ route('notifications.index') }}">
                    <span>Notifications</span>
                    @if ($unreadNotificationCount > 0)
                        <span class="topbar-count">{{ $unreadNotificationCount }}</span>
                    @endif
                </a>
                <div class="dropdown">
                    <button class="btn app-user-menu dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">{{ auth()->user()->name }}</button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <div class="px-3 py-2 border-bottom mb-1">
                            <strong class="d-block">{{ auth()->user()->name }}</strong>
                            <small class="text-secondary">{{ auth()->user()->email }}</small>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="dropdown-item" type="submit">Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="app-main" id="main-content">
            <div class="app-page-container">
                @include('partials.messages')
                @yield('content')
            </div>
        </main>
    </div>

    <div class="offcanvas offcanvas-start app-navigation-drawer" tabindex="-1" id="appNavigation" aria-labelledby="appNavigationLabel">
        <div class="offcanvas-header">
            <a class="app-brand" id="appNavigationLabel" href="{{ route('dashboard') }}">
                <span class="app-brand-mark" aria-hidden="true">O</span>
                <span><strong>OpsFlow</strong><small>Spend operations</small></span>
            </a>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close navigation"></button>
        </div>
        <div class="offcanvas-body">
            @include('partials.app-navigation')
        </div>
    </div>
@else
    <main class="container py-5">
        <div class="auth-card mx-auto">
            <div class="mb-4 text-center"><span class="h3 fw-bold">OpsFlow</span><p class="text-secondary mt-2">Spend management & approvals</p></div>
            <div class="card auth-surface"><div class="card-body p-4">
                <h1 class="h4 mb-4">@yield('title')</h1>
                @include('partials.messages')
                @yield('content')
            </div></div>
        </div>
    </main>
@endauth
@stack('scripts')
</body>
</html>
