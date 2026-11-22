<?php

namespace App\Http\Controllers;

use App\Models\ClinicNotification;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get active notifications with automatic synchronization.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Sync latest database alerts
        $this->notificationService->syncAll($user);

        // 2. Query notifications for this user or clinic-wide
        $query = ClinicNotification::query()
            ->where(function ($q) use ($user) {
                if ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhereNull('user_id');
                } else {
                    $q->whereNull('user_id');
                }
            });

        // Filter by category if requested
        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // Unread only filter
        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        $totalUnread = (clone $query)->where('is_read', false)->count();

        $notifications = $query
            ->orderBy('is_read', 'asc') // Unread first
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        // Category breakdown counts
        $counts = [
            'total' => ClinicNotification::where(function ($q) use ($user) {
                if ($user) $q->where('user_id', $user->id)->orWhereNull('user_id');
                else $q->whereNull('user_id');
            })->count(),
            'unread' => $totalUnread,
            'medication_reminders' => ClinicNotification::where('category', 'medication_reminder')->where('is_read', false)->count(),
            'patient_attention' => ClinicNotification::where('category', 'patient_attention')->where('is_read', false)->count(),
            'low_stock' => ClinicNotification::where('category', 'low_stock')->where('is_read', false)->count(),
            'expired_medicine' => ClinicNotification::where('category', 'expired_medicine')->where('is_read', false)->count(),
        ];

        return response()->json([
            'status' => 'success',
            'unread_count' => $totalUnread,
            'counts' => $counts,
            'data' => $notifications,
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, int|string $id): JsonResponse
    {
        $notification = ClinicNotification::find($id);

        if ($notification) {
            $notification->update([
                'is_read' => true,
                'read_at' => Carbon::now(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Notification marked as read.',
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        ClinicNotification::query()
            ->where(function ($q) use ($user) {
                if ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhereNull('user_id');
                } else {
                    $q->whereNull('user_id');
                }
            })
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => Carbon::now(),
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'All notifications marked as read.',
        ]);
    }

    /**
     * Delete a single notification.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $notification = ClinicNotification::find($id);

        if ($notification) {
            $notification->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Notification removed.',
        ]);
    }

    /**
     * Clear all notifications for the user.
     */
    public function clearAll(Request $request): JsonResponse
    {
        $user = $request->user();

        ClinicNotification::query()
            ->where(function ($q) use ($user) {
                if ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhereNull('user_id');
                } else {
                    $q->whereNull('user_id');
                }
            })
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'All notifications cleared.',
        ]);
    }
}
