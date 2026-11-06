<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\AiConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiConversationController extends Controller
{
    public function __construct(private readonly AiConversationService $conversations) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['conversations' => $this->conversations->listFor($request->user())->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->title,
            'product' => $c->product,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ])->values()]);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $found = $this->conversations->findFor($request->user(), $conversation);
        abort_if($found === null, 404);

        return response()->json([
            'conversation' => ['id' => $found->id, 'title' => $found->title, 'product' => $found->product],
            'messages' => $this->conversations->present($found),
        ]);
    }

    public function destroy(Request $request, int $conversation): JsonResponse
    {
        $found = $this->conversations->findFor($request->user(), $conversation);
        abort_if($found === null, 404);

        $this->conversations->delete($found);

        return response()->json(['message' => __('Conversation deleted.')]);
    }
}
