@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<div class="mb-4"><h1 class="h2 mb-1">Notifications</h1><p class="text-secondary mb-0">Workflow updates and requests that need your attention.</p></div>
<div class="card border-0 shadow-sm"><div class="list-group list-group-flush">
    @forelse ($notifications as $notification)
        <div class="list-group-item {{ $notification->read_at ? '' : 'bg-light' }}"><div class="d-flex flex-wrap justify-content-between gap-3"><div><a class="fw-semibold text-decoration-none" href="{{ $notification->data['target_url'] }}">{{ $notification->data['message'] }}</a><div class="small text-secondary mt-1">{{ $notification->created_at->diffForHumans() }}</div></div>@if (! $notification->read_at)<form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="btn btn-sm btn-outline-secondary" type="submit">Mark read</button></form>@else<span class="badge text-bg-secondary align-self-start">Read</span>@endif</div></div>
    @empty
        <div class="text-center text-secondary py-5"><strong class="d-block text-body mb-1">No notifications yet.</strong>Workflow assignments and important status changes will appear here.</div>
    @endforelse
</div></div>
<div class="mt-3">{{ $notifications->links() }}</div>
@endsection
