<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GitPlatformListRequest;
use App\Services\Integrations\Git\GitConnectionException;
use App\Services\Integrations\Git\GitPlatformService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Platform staff: connection health, delivery log, re-sync and pause. Metadata only - never tenant repository content. */
class GitPlatformController extends Controller
{
    public function __construct(private readonly GitPlatformService $platform) {}

    public function connections(GitPlatformListRequest $request): JsonResponse
    {
        $page = $this->platform->connections($request->validated('status'), $request->validated('q'));

        return response()->json(['connections' => $page->items(), 'health' => $this->platform->health(), 'pagination' => $this->paginate($page)]);
    }

    public function deliveries(GitPlatformListRequest $request): JsonResponse
    {
        $page = $this->platform->deliveries($request->validated('status'), $request->validated('connection'));

        return response()->json(['deliveries' => $page->items(), 'pagination' => $this->paginate($page)]);
    }

    public function sync(Request $request, string $uuid): JsonResponse
    {
        return $this->act(fn () => $this->platform->sync($uuid, $request->user(), $request), __('Repository sync started.'), 202);
    }

    public function disable(Request $request, string $uuid): JsonResponse
    {
        return $this->act(fn () => $this->platform->disable($uuid, $request->user(), $request), __('Connection disabled.'));
    }

    public function enable(Request $request, string $uuid): JsonResponse
    {
        return $this->act(fn () => $this->platform->enable($uuid, $request->user(), $request), __('Connection enabled.'));
    }

    private function act(callable $action, string $message, int $status = 200): JsonResponse
    {
        try {
            $action();
        } catch (GitConnectionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => $message], $status);
    }

    /** @return array<string, int> */
    private function paginate($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
