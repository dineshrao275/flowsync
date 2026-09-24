<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->service->forUser($request->user());

        return response()->json([
            'notifications' => collect($notifications->items())
                ->map(fn (UserNotification $notification) => $this->present($notification))
                ->values(),
            'unread_count' => $this->service->unreadCount($request->user()),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $this->service->unreadCount($request->user()),
        ]);
    }

    public function markRead(Request $request, UserNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(404);
        }

        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->service->markAllRead($request->user());

        return response()->json([
            'message' => 'All notifications marked as read.',
            'count' => $count,
        ]);
    }

    private function present(UserNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
            'actor' => $notification->actor ? [
                'id' => $notification->actor->id,
                'name' => $notification->actor->name,
            ] : null,
        ];
    }
}
