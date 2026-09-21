<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponse;

    /**
     * Get paginated notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 50);
        if ($perPage < 1) {
            $perPage = 20;
        }

        $query = $request->boolean('unread_only')
            ? $request->user()->unreadNotifications()
            : $request->user()->notifications();

        $notifications = $query->paginate($perPage);

        return $this->success($notifications, 'Notifications retrieved.');
    }

    /**
     * Get unread notifications count for lightweight polling.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->unreadNotifications()->count();

        return $this->success([
            'unread_count' => $count,
            'server_time' => now('Asia/Kolkata')->toIso8601String(),
        ], 'Unread count retrieved.');
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (!$notification) {
            return $this->notFound('Notification not found or access denied.');
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return $this->success(null, 'Notification marked as read.');
    }

    /**
     * Mark all unread notifications as read for the authenticated user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->success(null, 'All notifications marked as read.');
    }
}
