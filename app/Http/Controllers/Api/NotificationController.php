<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** إشعارات المستخدم الحالي فقط (FR-33). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $items = PlatformNotification::query()
            ->join('notification_user', 'notification_user.notification_id', '=', 'notifications.id')
            ->where('notification_user.user_id', $userId)
            ->orderByDesc('notifications.id')
            ->limit(30)
            ->get(['notifications.*', 'notification_user.read_at']);

        return response()->json([
            'success' => true,
            'unread_count' => DB::table('notification_user')->where('user_id', $userId)->whereNull('read_at')->count(),
            'data' => $items->map(fn (PlatformNotification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'body' => $notification->body,
                'link' => $notification->data['link'] ?? null,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
            ])->values(),
        ]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $updated = DB::table('notification_user')
            ->where('user_id', $request->user()->id)
            ->where('notification_id', $notification)
            ->update(['read_at' => now()]);
        abort_unless($updated > 0 || DB::table('notification_user')->where('user_id', $request->user()->id)->where('notification_id', $notification)->exists(), 404);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        DB::table('notification_user')->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
