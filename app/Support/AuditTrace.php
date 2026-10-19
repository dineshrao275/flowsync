<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Who/what-request context stamped on every tenant audit ledger row:
 * the correlation `request_id` and, for an impersonated session, the platform
 * super admin behind it (`impersonator_id`, a central user id). Columns that
 * have not been migrated yet are skipped, so an audit write never fails on a
 * database that is mid-rollout (run `tenants:provision`).
 */
final class AuditTrace
{
    /** The super admin driving the current impersonated session, if any. */
    public static function impersonatorId(): ?int
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get('impersonate.original_user_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array{request_id?: string, impersonator_id?: int}
     */
    public static function columnsFor(Model $ledger): array
    {
        $columns = RequestId::columnFor($ledger);
        $impersonator = self::impersonatorId();

        if ($impersonator !== null) {
            $schema = $ledger->getConnection()->getSchemaBuilder();

            if ($schema->hasColumn($ledger->getTable(), 'impersonator_id')) {
                $columns['impersonator_id'] = $impersonator;
            }
        }

        return $columns;
    }
}
