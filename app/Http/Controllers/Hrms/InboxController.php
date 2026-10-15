<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Concerns\DetectsPlatformUsers;
use App\Http\Controllers\Controller;
use App\Services\Hrms\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbox/HRMS — one queue over HTTP.
 *
 * Thin and self-scoped like notifications: no policy check, because every
 * row is the caller's own — the service scopes by login, and a login
 * without anything pending reads an empty list, not a 403. Pagination
 * rides the shared shape; reads write the per-user ledger.
 */
class InboxController extends Controller
{
    use DetectsPlatformUsers;

    public function __construct(private readonly InboxService $inbox) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->isPlatformSuperAdmin($request)) {
            return response()->json([
                'items' => [],
                'unread_count' => 0,
                'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0],
            ]);
        }

        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->inbox->items(
            $request->user(),
            (int) ($filters['page'] ?? 1),
            (int) ($filters['per_page'] ?? 20),
        );

        return response()->json([
            'items' => $page->items(),
            'unread_count' => $this->inbox->unreadCount($request->user()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        if ($this->isPlatformSuperAdmin($request)) {
            return response()->json(['marked' => 0, 'unread_count' => 0]);
        }

        $validated = $request->validate([
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['string', 'max:120'],
        ]);

        return response()->json([
            'marked' => $this->inbox->markRead($request->user(), $validated['keys']),
            'unread_count' => $this->inbox->unreadCount($request->user()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        if ($this->isPlatformSuperAdmin($request)) {
            return response()->json(['marked' => 0, 'unread_count' => 0]);
        }

        return response()->json([
            'marked' => $this->inbox->markAllRead($request->user()),
            'unread_count' => $this->inbox->unreadCount($request->user()),
        ]);
    }
}
