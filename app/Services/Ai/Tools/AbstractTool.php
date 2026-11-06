<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\User;

/** Defaults for the common case: no modules, no product, no extra availability rule. */
abstract class AbstractTool implements AiTool
{
    public function modules(): array
    {
        return [];
    }

    public function product(): ?string
    {
        return null;
    }

    public function permission(): ?string
    {
        return null;
    }

    public function available(User $user): bool
    {
        return true;
    }

    /** @param array<string,mixed> $properties */
    protected function schema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
