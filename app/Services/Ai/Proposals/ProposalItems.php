<?php

declare(strict_types=1);

namespace App\Services\Ai\Proposals;

use App\Models\Priority;
use Illuminate\Support\Str;

/**
 * The one definition of a valid proposed task. Model output and user edits both pass through here,
 * so a reviewed item and a generated one are held to the same rules, and nothing the model invents
 * (an unknown priority, a 10,000-character title, a thousand items) survives to confirmation.
 */
final class ProposalItems
{
    public const KINDS = ['task', 'requirement', 'story', 'dependency', 'risk'];

    private const TITLE_MAX = 255;

    private const DESCRIPTION_MAX = 6000;

    /**
     * Priority slugs this tenant actually has (tenants rename and add them), so a proposal can never name
     * one that does not exist. Falls back to the shipped defaults when the catalog is empty.
     *
     * @return list<string>
     */
    public static function priorities(): array
    {
        $slugs = Priority::query()->orderByDesc('value')->pluck('slug')->all();

        return $slugs !== [] ? array_values($slugs) : array_column((array) config('priorities.priorities', []), 'slug');
    }

    /**
     * @param  array<int,mixed>  $items
     * @return list<array<string,mixed>>
     */
    public static function normalize(array $items): array
    {
        $out = [];
        $max = (int) config('ai.proposal_max_items');

        foreach ($items as $item) {
            if (! is_array($item) || count($out) >= $max) {
                continue;
            }

            $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($item['title'] ?? '')));
            if ($title === '') {
                continue;
            }

            $out[] = [
                'id' => self::id($item['id'] ?? null),
                'title' => Str::limit($title, self::TITLE_MAX, ''),
                'description' => Str::limit(self::description($item), self::DESCRIPTION_MAX, ''),
                'kind' => in_array($item['kind'] ?? null, self::KINDS, true) ? $item['kind'] : 'task',
                'priority' => in_array($item['priority'] ?? null, self::priorities(), true) ? $item['priority'] : null,
                'story_points' => self::points($item['story_points'] ?? null),
                'selected' => array_key_exists('selected', $item) ? (bool) $item['selected'] : true,
                'duplicate_of' => isset($item['duplicate_of']) && is_string($item['duplicate_of']) ? Str::limit($item['duplicate_of'], 40, '') : null,
            ];
        }

        return $out;
    }

    private static function id(mixed $id): string
    {
        return is_string($id) && Str::isUuid($id) ? $id : (string) Str::uuid();
    }

    /** Acceptance criteria from the model are folded into the description, where the task UI shows them. */
    private static function description(array $item): string
    {
        $text = trim(strip_tags((string) ($item['description'] ?? '')));
        $criteria = array_values(array_filter(array_map(
            fn ($c) => is_string($c) ? trim(strip_tags($c)) : '',
            (array) ($item['acceptance_criteria'] ?? []),
        )));

        if ($criteria === []) {
            return $text;
        }

        return trim($text."\n\n".__('Acceptance criteria').":\n".implode("\n", array_map(fn ($c) => "- {$c}", $criteria)));
    }

    private static function points(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return round(min((float) $value, 999.99), 2);
    }

    /** JSON Schema the model must answer with (all fields required: strict structured output demands it). */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'kind' => ['type' => 'string', 'enum' => self::KINDS],
                            'priority' => ['type' => 'string', 'enum' => self::priorities()],
                            'story_points' => ['type' => 'number'],
                        ],
                        'required' => ['title', 'description', 'acceptance_criteria', 'kind', 'priority', 'story_points'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['tasks'],
            'additionalProperties' => false,
        ];
    }
}
