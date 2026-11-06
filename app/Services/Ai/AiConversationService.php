<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\User;
use App\Services\Ai\Dto\AiMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Conversations belong to ONE user. Every lookup is scoped by user id, so another user's
 * conversation id behaves exactly like a missing one (404), and history sent back to the model
 * is only the plain user/assistant text: tool data is re-fetched fresh each turn, so an old
 * answer can never smuggle rows the user can no longer see back into a prompt.
 */
final class AiConversationService
{
    private const LIST_LIMIT = 50;

    public function start(User $user, ?string $product, string $firstMessage): AiConversation
    {
        return AiConversation::create([
            'user_id' => $user->id,
            'title' => Str::limit(trim(preg_replace('/\s+/', ' ', $firstMessage) ?? ''), 80, '…'),
            'product' => $product,
            'last_message_at' => now(),
        ]);
    }

    public function findFor(User $user, int $id): ?AiConversation
    {
        return AiConversation::query()->where('user_id', $user->id)->find($id);
    }

    /** @return Collection<int, AiConversation> */
    public function listFor(User $user): Collection
    {
        return AiConversation::query()->where('user_id', $user->id)
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();
    }

    /** @return list<AiMessage> the last `$limit` user/assistant turns, oldest first */
    public function history(AiConversation $conversation, int $limit): array
    {
        return $conversation->messages()->whereIn('role', [AiMessage::USER, AiMessage::ASSISTANT])
            ->orderByDesc('id')->limit($limit)->get()->reverse()
            ->map(fn (AiConversationMessage $m) => new AiMessage($m->role, (string) $m->content))
            ->values()->all();
    }

    /** @param array<string,mixed>|null $payload */
    public function append(AiConversation $conversation, string $role, string $content, ?array $payload = null, int $in = 0, int $out = 0): AiConversationMessage
    {
        $message = $conversation->messages()->create([
            'role' => $role, 'content' => $content, 'payload' => $payload, 'input_tokens' => $in, 'output_tokens' => $out,
        ]);
        $conversation->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    /** @return list<array<string,mixed>> */
    public function present(AiConversation $conversation): array
    {
        return $conversation->messages()->whereIn('role', [AiMessage::USER, AiMessage::ASSISTANT])->orderBy('id')->get()
            ->map(fn (AiConversationMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'cards' => $m->payload['cards'] ?? [],
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all();
    }

    public function delete(AiConversation $conversation): void
    {
        $conversation->delete();
    }
}
