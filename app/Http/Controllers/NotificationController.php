<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DetectsPlatformUsers;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal notifications. The `notifications` table only exists in tenant
 * databases, so a platform super admin (no tenant context) gets empty payloads
 * instead of a database error.
 */
class NotificationController extends Controller
{
    use DetectsPlatformUsers;

    public function __construct(private readonly NotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->isPlatformSuperAdmin($request)) {
            return response()->json([
                'notifications' => [],
                'unread_count' => 0,
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                ],
            ]);
        }

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
        if ($this->isPlatformSuperAdmin($request)) {
            return response()->json(['count' => 0]);
        }

        return response()->json([
            'count' => $this->service->unreadCount($request->user()),
        ]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $model = $this->isPlatformSuperAdmin($request) ? null : UserNotification::find($notification);

        if ($model === null || $model->user_id !== $request->user()->id) {
            abort(404);
        }

        $model->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->isPlatformSuperAdmin($request) ? 0 : $this->service->markAllRead($request->user());

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
