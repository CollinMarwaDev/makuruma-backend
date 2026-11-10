<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** The signed-in user's in-app notifications (contract 2.2 D-6/D-7). */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return [
            'data' => $user->notifications()->latest()->limit(50)->get(['id', 'data', 'read_at', 'created_at']),
            'unread_count' => $user->unreadNotifications()->count(),
        ];
    }

    public function markRead(Request $request, string $id)
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(null, 204);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(null, 204);
    }
}
