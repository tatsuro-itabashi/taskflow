<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * 未読通知一覧を返す
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->unreadNotifications()        // 未読のみ
            ->latest()
            ->take(20)                     // 最大20件
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'data' => $n->data,
                'created_at' => $n->created_at->diffForHumans(), // 例："3分前"
            ]);

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * 特定の通知を既読にする
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json(['message' => '既読にしました']);
    }

    /**
     * 全通知を既読にする
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => '全て既読にしました']);
    }
}
