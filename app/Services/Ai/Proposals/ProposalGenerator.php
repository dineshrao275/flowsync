<?php

declare(strict_types=1);

namespace App\Services\Ai\Proposals;

use App\Models\AiDocument;
use App\Models\AiProposal;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiUsageService;
use App\Services\Ai\Documents\ExtractedDocument;
use App\Services\Ai\Dto\AiMessage;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiUsage;
use App\Services\Ai\Exceptions\AiErrorKind;
use App\Services\Ai\Exceptions\AiProviderException;

/** Asks the model for task proposals and stores the validated result as a draft. Creates nothing in the project. */
final class ProposalGenerator
{
    public function __construct(
        private readonly AiProviderResolver $resolver,
        private readonly AiUsageService $usage,
        private readonly DuplicateDetector $duplicates,
    ) {}

    public function generate(User $user, Project $project, ExtractedDocument $doc, ?AiDocument $source, ?string $instructions): AiProposal
    {
        $provider = $this->resolver->provider();
        $this->usage->assertWithinLimits();

        // Providers that can enforce a schema do; the rest are asked for JSON and held to the same validation.
        $schema = $provider->capabilities()->jsonSchemaOutput ? ProposalItems::schema() : null;
        $messages = [
            AiMessage::system(ProposalPrompt::system($schema !== null)),
            AiMessage::user(ProposalPrompt::user($doc->text, $instructions)),
        ];

        $items = null;
        $total = new AiUsage;

        for ($attempt = 0; $attempt < 2 && $items === null; $attempt++) {
            try {
                $response = $provider->complete(new AiRequest($messages, [], (int) config('ai.max_output_tokens'), 0.1, $schema));
            } catch (AiProviderException $e) {
                $this->usage->record($total, $provider->model(), failed: true, documentPages: $doc->pages);

                throw $e;
            }

            $total = $total->plus($response->usage);
            $items = $this->parse($response->text);

            if ($items === null) {
                $messages[] = AiMessage::assistant($response->text);
                $messages[] = AiMessage::user('That was not valid. Reply again with ONLY the JSON object described, with a non-empty "tasks" array.');
            }
        }

        $this->usage->record($total, $provider->model(), failed: $items === null, documentPages: $doc->pages);

        if ($items === null) {
            throw new AiProviderException(AiErrorKind::BadOutput, 'The model did not return usable task proposals.', $provider->name());
        }

        return AiProposal::create([
            'user_id' => $user->id,
            'kind' => 'tasks',
            'project_id' => $project->id,
            'document_id' => $source?->id,
            'status' => AiProposal::DRAFT,
            'payload' => [
                'items' => $this->duplicates->flag($items, $project, $user),
                'truncated' => $doc->truncated,
                'pages' => $doc->pages,
            ],
        ]);
    }

    /** @return list<array<string,mixed>>|null null = unusable, so the caller can retry */
    private function parse(string $text): ?array
    {
        $json = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? '');
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! isset($decoded['tasks']) || ! is_array($decoded['tasks'])) {
            return null;
        }

        $items = ProposalItems::normalize($decoded['tasks']);

        return $items === [] ? null : $items;
    }
}
