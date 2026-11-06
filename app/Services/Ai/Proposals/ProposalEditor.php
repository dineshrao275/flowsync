<?php

declare(strict_types=1);

namespace App\Services\Ai\Proposals;

use App\Models\AiProposal;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Applies a reviewer's edits (change, add, remove, untick). Items already created are frozen: editing history is not a thing here. */
final class ProposalEditor
{
    /** @param array<int,mixed> $incoming */
    public function update(User $user, string $id, array $incoming): AiProposal
    {
        $proposal = AiProposal::query()->where('user_id', $user->id)->findOrFail($id);

        if (! in_array($proposal->status, [AiProposal::DRAFT, AiProposal::PARTIAL, AiProposal::FAILED], true)) {
            throw ValidationException::withMessages(['form' => __('This proposal can no longer be edited.')]);
        }

        $results = (array) ($proposal->result['items'] ?? []);
        $frozen = collect($proposal->payload['items'])->filter(fn ($i) => ($results[$i['id']]['status'] ?? null) === 'created')->keyBy('id');

        // The id is the identity: an edit to a created item is ignored, and a created item cannot be dropped.
        $edited = collect(ProposalItems::normalize($incoming))->reject(fn ($i) => $frozen->has($i['id']))->all();
        $items = array_slice([...$frozen->values()->all(), ...$edited], 0, (int) config('ai.proposal_max_items'));

        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('A proposal needs at least one task.')]);
        }

        $proposal->forceFill(['payload' => array_merge($proposal->payload, ['items' => $items])])->save();

        return $proposal;
    }

    public function reject(User $user, string $id): AiProposal
    {
        $proposal = AiProposal::query()->where('user_id', $user->id)->findOrFail($id);

        if ($proposal->status === AiProposal::DRAFT) {
            $proposal->forceFill(['status' => AiProposal::REJECTED])->save();
        }

        return $proposal;
    }
}
