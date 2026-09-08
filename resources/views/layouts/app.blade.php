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
    <header class="navbar navbar-dark bg-dark px-3 py-3">
        <a class="navbar-brand fw-bold" href="{{ route('dashboard') }}">OpsFlow</a>
        <div class="d-flex align-items-center gap-3 text-white">
            <span>{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn btn-outline-light btn-sm" type="submit">Sign out</button>
            </form>
        </div>
    </header>
    <div class="d-md-flex min-vh-100">
        <aside class="app-sidebar bg-white border-end p-3">
            <nav aria-label="Main navigation" class="nav nav-pills flex-column">
                <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Dashboard</a>
                @can('viewAny', App\Models\Department::class)
                    <span class="text-uppercase text-secondary small fw-semibold mt-4 mb-2 px-3">Administration</span>
                    <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">Users</a>
                    <a class="nav-link {{ request()->routeIs('departments.*') ? 'active' : '' }}" href="{{ route('departments.index') }}">Departments</a>
                    <a class="nav-link {{ request()->routeIs('spend-categories.*') ? 'active' : '' }}" href="{{ route('spend-categories.index') }}">Spend categories</a>
                    <a class="nav-link {{ request()->routeIs('vendors.*') ? 'active' : '' }}" href="{{ route('vendors.index') }}">Vendors</a>
                    <a class="nav-link {{ request()->routeIs('workflow-*') ? 'active' : '' }}" href="{{ route('workflow-templates.index') }}">Workflows</a>
                @endcan
            </nav>
        </aside>
        <main class="flex-grow-1 p-3 p-md-5" id="main-content">
            @include('partials.messages')
            @yield('content')
        </main>
    </div>
@else
    <main class="container py-5">
        <div class="auth-card mx-auto">
            <div class="mb-4 text-center"><span class="h3 fw-bold">OpsFlow</span><p class="text-secondary mt-2">Spend management & approvals</p></div>
            <div class="card border-0 shadow-sm"><div class="card-body p-4">
                <h1 class="h4 mb-4">@yield('title')</h1>
                @include('partials.messages')
                @yield('content')
            </div></div>
        </div>
    </main>
@endauth
</body>
</html>
