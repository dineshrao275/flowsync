<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiProposal;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\Documents\AiDocumentService;
use App\Services\Ai\Proposals\ProposalConfirmer;
use App\Services\Ai\Proposals\ProposalEditor;
use App\Services\Ai\Proposals\ProposalGenerator;
use App\Services\Ai\Proposals\ProposalPresenter;
use Illuminate\Validation\ValidationException;

/** Entry point for document-to-task proposals. Each step lives in Proposals/; this only wires them. */
final class AiProposalService
{
    public function __construct(
        private readonly AiDocumentService $documents,
        private readonly ProposalGenerator $generator,
        private readonly ProposalConfirmer $confirmer,
        private readonly ProposalEditor $editor,
        private readonly ProposalPresenter $presenter,
    ) {}

    public function generate(User $user, Project $project, ?int $documentId, ?string $text, ?string $instructions): AiProposal
    {
        if ($documentId === null && ($text === null || trim($text) === '')) {
            throw ValidationException::withMessages(['text' => __('Upload a document or paste some text.')]);
        }

        [$extracted, $document] = $documentId !== null
            ? $this->documents->read($user, $documentId)
            : [$this->documents->fromText((string) $text), null];

        return $this->generator->generate($user, $project, $extracted, $document, $instructions);
    }

    public function find(User $user, string $id): AiProposal
    {
        return AiProposal::query()->where('user_id', $user->id)->findOrFail($id);
    }

    /** @param array<int,mixed> $items */
    public function update(User $user, string $id, array $items): AiProposal
    {
        return $this->editor->update($user, $id, $items);
    }

    public function confirm(User $user, string $id, ?string $ip): AiProposal
    {
        return $this->confirmer->confirm($user, $id, $ip);
    }

    public function reject(User $user, string $id): AiProposal
    {
        return $this->editor->reject($user, $id);
    }

    /** @return array<string,mixed> */
    public function present(AiProposal $proposal): array
    {
        return $this->presenter->present($proposal);
    }
}
