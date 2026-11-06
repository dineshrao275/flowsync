<?php

declare(strict_types=1);

namespace App\Services\Ai\Proposals;

use App\Models\AiProposal;
use App\Models\Priority;
use App\Services\Ai\Tools\AiLinks;

final class ProposalPresenter
{
    /** @return array<string,mixed> */
    public function present(AiProposal $proposal): array
    {
        $results = (array) ($proposal->result['items'] ?? []);

        return [
            'id' => $proposal->id,
            'status' => $proposal->status,
            'project_id' => $proposal->project_id,
            'truncated' => (bool) ($proposal->payload['truncated'] ?? false),
            'priorities' => Priority::query()->orderByDesc('value')->get(['slug', 'name'])->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name])->all(),
            'items' => array_map(function (array $item) use ($results): array {
                $result = $results[$item['id']] ?? null;

                return $item + [
                    'result' => $result === null ? null : [
                        'status' => $result['status'],
                        'error' => $result['error'] ?? null,
                        'key' => $result['key'] ?? null,
                        'link' => isset($result['key']) ? AiLinks::task((int) $result['project_id'], $result['key']) : null,
                    ],
                ];
            }, (array) $proposal->payload['items']),
        ];
    }
}
