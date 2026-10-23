<?php

declare(strict_types=1);

namespace App\Services\CustomFields;

use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Tenant;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Custom fields — definitions per entity and typed values per record.
 *
 * One definition = one field on one entity (`employee`, `task`, …). A value is stored once as JSON
 * (the canonical form handed back to clients) and mirrored into a typed shadow column
 * (`value_text` / `value_number` / `value_date`) so lists can filter and sort in SQL.
 *
 * Validation is the single place a value is interpreted: `normalize()` coerces the raw input per
 * type or throws a 422 keyed `custom_fields.<key>`, so every entity that accepts custom fields
 * gets identical rules. Entities are plain strings so the engine has no dependency on the TMS or
 * HRMS schema sets; the caller supplies the record id.
 */
class CustomFieldService
{
    public const ENTITIES = ['employee', 'task', 'project', 'asset', 'expense', 'document'];

    public const TYPES = [
        'text', 'textarea', 'number', 'currency', 'date', 'datetime', 'boolean',
        'select', 'multiselect', 'url', 'email', 'user',
    ];

    private const SELECT_TYPES = ['select', 'multiselect'];

    private const MAX_FIELDS_PER_ENTITY = 50;

    /**
     * Whether the current tenant's plan includes custom fields. Entities call this before touching
     * values so a tenant without the module neither stores nor shows them (and a plan downgrade
     * hides, rather than deletes, what was entered).
     */
    public function enabled(): bool
    {
        $tenantId = app(TenantContext::class)->currentId();

        if ($tenantId === null) {
            return false;
        }

        $tenant = Tenant::query()->find($tenantId);

        return $tenant !== null && app(TenantLimits::class)->hasModule($tenant, 'custom_fields');
    }

    /** @return Collection<int, CustomFieldDefinition> */
    public function definitions(string $entity, bool $activeOnly = true): Collection
    {
        return CustomFieldDefinition::query()
            ->where('entity', $entity)
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): CustomFieldDefinition
    {
        $entity = $data['entity'];
        $this->assertEntity($entity);
        $this->assertType($data['type']);

        if (CustomFieldDefinition::where('entity', $entity)->count() >= self::MAX_FIELDS_PER_ENTITY) {
            throw ValidationException::withMessages(['form' => __('A maximum of :max custom fields per record type is allowed.', ['max' => self::MAX_FIELDS_PER_ENTITY])]);
        }

        $key = $this->uniqueKey($entity, $data['key'] ?? $data['label']);

        return CustomFieldDefinition::create([
            'entity' => $entity,
            'key' => $key,
            'label' => $data['label'],
            'type' => $data['type'],
            'options' => $this->cleanOptions($data['type'], $data['options'] ?? null),
            'required' => (bool) ($data['required'] ?? false),
            'is_filterable' => (bool) ($data['is_filterable'] ?? true),
            'is_sensitive' => (bool) ($data['is_sensitive'] ?? false),
            'is_active' => true,
            'position' => (int) ($data['position'] ?? CustomFieldDefinition::where('entity', $entity)->max('position') + 1),
            'help_text' => $data['help_text'] ?? null,
        ]);
    }

    /**
     * The key, entity and type are immutable once created: values are stored against them, and a
     * type change would silently reinterpret every stored value. Labels, options (a select may only
     * gain options or rename them — removing one that is in use is refused) and flags may change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CustomFieldDefinition $definition, array $data): CustomFieldDefinition
    {
        if (array_key_exists('options', $data) && in_array($definition->type, self::SELECT_TYPES, true)) {
            $options = $this->cleanOptions($definition->type, $data['options']);
            $this->assertNoUsedOptionRemoved($definition, $options);
            $data['options'] = $options;
        } else {
            unset($data['options']);
        }

        $definition->fill(array_intersect_key($data, array_flip([
            'label', 'options', 'required', 'is_filterable', 'is_sensitive', 'is_active', 'position', 'help_text',
        ])))->save();

        return $definition->refresh();
    }

    public function delete(CustomFieldDefinition $definition): void
    {
        $definition->delete(); // values cascade
    }

    /**
     * Coerce and validate submitted values for one entity. Unknown keys are 422 (a typo must not
     * silently drop data). With `$partial` only the submitted keys are checked, otherwise every
     * active required field must be present.
     *
     * @param  array<string, mixed>  $input  key => raw value
     * @return array<int, mixed> definition id => normalized value (null = clear)
     */
    public function validate(string $entity, array $input, bool $partial = true): array
    {
        $definitions = $this->definitions($entity)->keyBy('key');
        $errors = [];
        $clean = [];

        foreach ($input as $key => $raw) {
            if (! $definitions->has($key)) {
                $errors["custom_fields.$key"] = __('Unknown custom field.');
            }
        }

        foreach ($definitions as $key => $definition) {
            $present = array_key_exists($key, $input);

            if (! $present && ($partial || ! $definition->required)) {
                continue;
            }

            try {
                $value = $this->normalize($definition, $present ? $input[$key] : null);
            } catch (\InvalidArgumentException $e) {
                $errors["custom_fields.$key"] = $e->getMessage();

                continue;
            }

            if ($definition->required && FieldValueNormalizer::isEmpty($value)) {
                $errors["custom_fields.$key"] = __('This field is required.');

                continue;
            }

            $clean[$definition->id] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * Persist already-validated values (definition id => value). A null/empty value removes the row.
     *
     * @param  array<int, mixed>  $values
     */
    public function save(string $entity, int $entityId, array $values): void
    {
        if ($values === []) {
            return;
        }

        $definitions = CustomFieldDefinition::query()->whereIn('id', array_keys($values))->get()->keyBy('id');

        DB::transaction(function () use ($entity, $entityId, $values, $definitions): void {
            foreach ($values as $definitionId => $value) {
                $definition = $definitions[$definitionId] ?? null;

                if ($definition === null || $definition->entity !== $entity) {
                    continue;
                }

                $query = CustomFieldValue::query()->where('definition_id', $definitionId)->where('entity_id', $entityId);

                if (FieldValueNormalizer::isEmpty($value)) {
                    $query->delete();

                    continue;
                }

                $query->updateOrCreate([], ['definition_id' => $definitionId, 'entity' => $entity, 'entity_id' => $entityId, 'value' => $value] + $this->shadow($definition, $value));
            }
        });
    }

    /**
     * Values for a page of records, grouped by record id then field key. Sensitive fields are
     * dropped unless `$withSensitive`. Inactive fields are never returned.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function valuesFor(string $entity, array $ids, bool $withSensitive = false): array
    {
        if ($ids === []) {
            return [];
        }

        $definitions = $this->definitions($entity)->when(! $withSensitive, fn ($c) => $c->where('is_sensitive', false))->keyBy('id');

        $out = array_fill_keys($ids, []);

        CustomFieldValue::query()
            ->where('entity', $entity)
            ->whereIn('entity_id', $ids)
            ->whereIn('definition_id', $definitions->keys())
            ->get()
            ->each(function (CustomFieldValue $row) use (&$out, $definitions): void {
                $out[$row->entity_id][$definitions[$row->definition_id]->key] = $row->value;
            });

        return $out;
    }

    /**
     * Narrow a query on the entity's own table by custom-field filters (`key => value`, or for
     * number/date `key => ['from' => , 'to' => ]`). Only active, filterable fields apply; the
     * rest are ignored so a stale bookmark cannot error.
     *
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, string $entity, array $filters, string $idColumn = 'id'): Builder
    {
        if ($filters === []) {
            return $query;
        }

        $definitions = $this->definitions($entity)->where('is_filterable', true)->keyBy('key');

        foreach ($filters as $key => $filter) {
            $definition = $definitions->get($key);

            if ($definition === null || $filter === null || $filter === '' || $filter === []) {
                continue;
            }

            $query->whereIn($idColumn, function ($sub) use ($definition, $filter): void {
                $sub->select('entity_id')->from('custom_field_values')->where('definition_id', $definition->id);
                $this->constrain($sub, $definition, $filter);
            });
        }

        return $query;
    }

    /** @return mixed the normalized value, or null when empty */
    public function normalize(CustomFieldDefinition $definition, mixed $raw): mixed
    {
        return (new FieldValueNormalizer)->normalize($definition, $raw);
    }

    // -- helpers -----------------------------------------------------------------------------

    /** @return array{value_text: ?string, value_number: ?float, value_date: ?string} */
    private function shadow(CustomFieldDefinition $definition, mixed $value): array
    {
        $shadow = ['value_text' => null, 'value_number' => null, 'value_date' => null];

        switch ($definition->type) {
            case 'number':
            case 'currency':
                $shadow['value_number'] = (float) $value;
                break;
            case 'date':
            case 'datetime':
                $shadow['value_date'] = substr((string) $value, 0, 10);
                break;
            case 'multiselect':
                $shadow['value_text'] = mb_substr(implode(',', $value), 0, 255);
                break;
            case 'boolean':
                $shadow['value_text'] = $value ? '1' : '0';
                break;
            default:
                $shadow['value_text'] = mb_substr((string) $value, 0, 255);
        }

        return $shadow;
    }

    private function constrain($sub, CustomFieldDefinition $definition, mixed $filter): void
    {
        switch ($definition->type) {
            case 'number':
            case 'currency':
                $range = is_array($filter) ? $filter : ['from' => $filter, 'to' => $filter];
                if (($range['from'] ?? '') !== '' && is_numeric($range['from'])) {
                    $sub->where('value_number', '>=', $range['from']);
                }
                if (($range['to'] ?? '') !== '' && is_numeric($range['to'])) {
                    $sub->where('value_number', '<=', $range['to']);
                }
                break;
            case 'date':
            case 'datetime':
                $range = is_array($filter) ? $filter : ['from' => $filter, 'to' => $filter];
                if (! empty($range['from'])) {
                    $sub->where('value_date', '>=', substr((string) $range['from'], 0, 10));
                }
                if (! empty($range['to'])) {
                    $sub->where('value_date', '<=', substr((string) $range['to'], 0, 10));
                }
                break;
            case 'boolean':
                $sub->where('value_text', filter_var($filter, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
                break;
            case 'multiselect':
                // The shadow is a comma list; match a whole option (wrapped in commas) to avoid prefix hits.
                $sub->whereRaw("(',' || value_text || ',') LIKE ?", ['%,'.str_replace(['%', '_', ','], '', (string) $filter).',%']);
                break;
            case 'text':
            case 'textarea':
            case 'url':
            case 'email':
                $sub->where('value_text', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filter).'%');
                break;
            default:
                $sub->where('value_text', (string) $filter);
        }
    }

    /**
     * @return list<array{value: string, label: string}>|null
     */
    private function cleanOptions(string $type, mixed $options): ?array
    {
        if (! in_array($type, self::SELECT_TYPES, true)) {
            return null;
        }

        if (! is_array($options) || $options === []) {
            throw ValidationException::withMessages(['options' => __('Add at least one option.')]);
        }

        $clean = [];
        foreach ($options as $option) {
            $label = trim((string) (is_array($option) ? ($option['label'] ?? $option['value'] ?? '') : $option));
            $value = trim((string) (is_array($option) ? ($option['value'] ?? Str::slug($label, '_')) : Str::slug($label, '_')));

            if ($label === '' || $value === '') {
                continue;
            }
            $clean[$value] = ['value' => $value, 'label' => mb_substr($label, 0, 80)];
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['options' => __('Add at least one option.')]);
        }

        if (count($clean) > 100) {
            throw ValidationException::withMessages(['options' => __('Too many options (maximum 100).')]);
        }

        return array_values($clean);
    }

    /** @param list<array{value: string, label: string}> $options */
    private function assertNoUsedOptionRemoved(CustomFieldDefinition $definition, array $options): void
    {
        $kept = array_column($options, 'value');
        $removed = array_diff(FieldValueNormalizer::optionValues($definition), $kept);

        foreach ($removed as $value) {
            $inUse = CustomFieldValue::query()->where('definition_id', $definition->id)->get()->contains(
                fn (CustomFieldValue $row) => in_array($value, (array) $row->value, true)
            );

            if ($inUse) {
                throw ValidationException::withMessages(['options' => __('The option ":option" is in use and cannot be removed.', ['option' => $value])]);
            }
        }
    }

    private function uniqueKey(string $entity, string $seed): string
    {
        $base = Str::slug($seed, '_') ?: 'field';
        $base = mb_substr($base, 0, 56);
        $key = $base;
        $n = 2;

        while (CustomFieldDefinition::where('entity', $entity)->where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }

    private function assertEntity(string $entity): void
    {
        if (! in_array($entity, self::ENTITIES, true)) {
            throw ValidationException::withMessages(['entity' => __('Unknown record type.')]);
        }
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('Unknown field type.')]);
        }
    }
}
