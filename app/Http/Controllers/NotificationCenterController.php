<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationCenterController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->notifications()
            ->latest()
            ->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $databaseNotification = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $databaseNotification->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }
}
