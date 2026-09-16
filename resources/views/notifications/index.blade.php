@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<x-page-header title="Notifications" description="Workflow updates and requests that need your attention." />

<div class="card notification-list">
    <div class="list-group list-group-flush">
        @forelse ($notifications as $notification)
            <div class="list-group-item notification-item {{ $notification->read_at ? '' : 'notification-item-unread' }}">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div class="notification-copy">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            @if (! $notification->read_at)<span class="notification-dot" aria-label="Unread"></span>@endif
                            <a class="fw-semibold text-decoration-none" href="{{ $notification->data['target_url'] }}">{{ $notification->data['message'] }}</a>
                        </div>
                        <div class="small text-secondary">{{ $notification->created_at->diffForHumans() }}</div>
                    </div>
                    @if (! $notification->read_at)
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="btn btn-sm btn-outline-secondary" type="submit">Mark as read</button></form>
                    @else
                        <span class="status-badge status-badge-secondary">Read</span>
                    @endif
                </div>
            </div>
        @empty
            <x-empty-state title="No notifications yet" description="Workflow assignments and important status changes will appear here." class="py-5" />
        @endforelse
    </div>
</div>
<div class="mt-3">{{ $notifications->links() }}</div>
@endsection
