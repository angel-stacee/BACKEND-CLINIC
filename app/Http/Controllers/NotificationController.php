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

        // 1. Sync latest database alerts (throttled on polling to avoid heavy synchronous queries on every request)
        if (! \Illuminate\Support\Facades\Cache::has('tcc_notifications_last_sync')) {
            $this->notificationService->syncAll($user);
            \Illuminate\Support\Facades\Cache::put('tcc_notifications_last_sync', true, 180);
        }

        $userScope = function ($q) use ($user) {
            if ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereNull('user_id');
            } else {
                $q->whereNull('user_id');
            }
        };

        // 2. Query notifications for this user or clinic-wide
        $query = ClinicNotification::query()->where($userScope);

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

        // Category breakdown counts (scoped to current user or clinic-wide)
        $scopedBase = ClinicNotification::query()->where($userScope);
        $counts = [
            'total' => (clone $scopedBase)->count(),
            'unread' => $totalUnread,
            'medication_reminders' => (clone $scopedBase)->where('category', 'medication_reminder')->where('is_read', false)->count(),
            'patient_attention' => (clone $scopedBase)->where('category', 'patient_attention')->where('is_read', false)->count(),
            'low_stock' => (clone $scopedBase)->where('category', 'low_stock')->where('is_read', false)->count(),
            'expired_medicine' => (clone $scopedBase)->where('category', 'expired_medicine')->where('is_read', false)->count(),
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
        $user = $request->user();
        $notification = ClinicNotification::findOrFail($id);

        if ($notification->user_id !== null && (! $user || (int) $notification->user_id !== (int) $user->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $notification->update([
            'is_read' => true,
            'read_at' => Carbon::now(),
        ]);

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
        $user = $request->user();
        $notification = ClinicNotification::findOrFail($id);

        if ($notification->user_id !== null && (! $user || (int) $notification->user_id !== (int) $user->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $notification->delete();

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
