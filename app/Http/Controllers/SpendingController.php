<?php

namespace App\Http\Controllers;

use App\Http\Resources\SpendingNotificationResource;
use App\Models\SpendingNotification;
use App\Services\SpendingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpendingController extends Controller
{
    public function __construct(private SpendingAnalysisService $spending) {}

    /**
     * GET /api/spending-status
     * Status hemat/normal/boros periode berjalan.
     */
    public function status(Request $request): JsonResponse
    {
        $result = $this->spending->analyze($request->user());

        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/notifications
     * Daftar notifikasi spending user, terbaru lebih dulu.
     */
    public function notifications(Request $request): AnonymousResourceCollection
    {
        $notifications = SpendingNotification::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return SpendingNotificationResource::collection($notifications);
    }

    /**
     * PATCH /api/notifications/{id}/read
     * Tandai notifikasi sebagai sudah dibaca.
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = SpendingNotification::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notifikasi telah ditandai dibaca.']);
    }
    /**
     * PATCH /api/notifications/read-all
     * Tandai semua notifikasi milik user sebagai sudah dibaca.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        SpendingNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'Semua notifikasi telah ditandai dibaca.']);
    }
}
