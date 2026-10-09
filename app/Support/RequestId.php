<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The correlation id AssignRequestId minted (or accepted) for the current
 * request, so audit rows can carry the same id that appears on every log
 * line and in the `X-Request-Id` response header. Null outside an HTTP
 * request (queued jobs, console) — nothing is invented there.
 */
final class RequestId
{
    public static function current(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $id = request()->attributes->get('request_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * `['request_id' => id]` for a ledger model, or `[]` when there is no id or
     * the table has not been migrated yet — so an audit write never fails on a
     * database that is mid-rollout (run `migrate` / `tenants:provision`).
     *
     * @return array{request_id?: string}
     */
    public static function columnFor(Model $ledger): array
    {
        $id = self::current();

        if ($id === null) {
            return [];
        }

        $schema = $ledger->getConnection()->getSchemaBuilder();

        return $schema->hasColumn($ledger->getTable(), 'request_id') ? ['request_id' => $id] : [];
    }
}
