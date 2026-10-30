<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Services\CustomFields\CustomFieldService;
use Illuminate\Http\Request;

/**
 * Shared by every controller whose entity accepts `custom_fields` on write and returns them on read.
 * All three steps are no-ops for a tenant whose plan lacks the module.
 */
trait HandlesCustomFields
{
    /**
     * Validate the submitted `custom_fields` (before the record is written, so a bad value cannot
     * leave a half-saved record) and return definition id => value.
     *
     * @return array<int, mixed>
     */
    protected function validatedCustomFields(Request $request, string $entity, bool $partial): array
    {
        $service = app(CustomFieldService::class);

        if (! $service->enabled()) {
            return [];
        }

        $input = $request->input('custom_fields', []);

        return $service->validate($entity, is_array($input) ? $input : [], $partial);
    }

    /** @param array<int, mixed> $values */
    protected function storeCustomFields(string $entity, int $entityId, array $values): void
    {
        app(CustomFieldService::class)->save($entity, $entityId, $values);
    }

    /**
     * Add a `custom_fields` map to each presented row (keyed by the row's `id`).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function withCustomFields(array $rows, string $entity, bool $withSensitive): array
    {
        $service = app(CustomFieldService::class);

        if ($rows === [] || ! $service->enabled()) {
            return $rows;
        }

        $values = $service->valuesFor($entity, array_column($rows, 'id'), $withSensitive);

        return array_map(fn (array $row) => $row + ['custom_fields' => (object) ($values[$row['id']] ?? [])], $rows);
    }
}
