<?php

namespace App\Support\Hrms;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit snapshots: identifiers and states only, never free text or figures.
 * Enums become their backing value; `$as` coerces a field (`float`, `int`,
 * `date`) where the stored cast is not what the ledger should hold.
 */
final class Auditable
{
    /**
     * @param  list<string>  $fields
     * @param  array<string, 'float'|'int'|'date'>  $as
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model, array $fields, array $as = []): array
    {
        $out = [];

        foreach ($fields as $field) {
            $value = $model->{$field};
            $coerce = $as[$field] ?? null;

            $out[$field] = match (true) {
                $coerce === 'float' => (float) $value,
                $coerce === 'int' => (int) $value,
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $coerce === 'date' ? $value->format('Y-m-d') : $value->format(DATE_ATOM),
                default => $value,
            };
        }

        return $out;
    }
}
