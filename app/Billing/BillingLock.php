<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * One billing mutation per tenant at a time (checkout, plan change, cancel, resume, card change). Two requests racing —
 * a double click, two admins, a webhook landing mid-change — would otherwise both read the same subscription and write
 * conflicting provider calls. The second caller gets a 409 and retries; it never blocks and never half-applies.
 */
final class BillingLock
{
    /** @var array<int, int> tenants this process already holds (the lock is re-entrant within one request) */
    private static array $held = [];

    /** @template T @param callable(): T $callback @return T */
    public static function for(Tenant|int $tenant, callable $callback)
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        if (isset(self::$held[$id])) {
            return $callback(); // a nested billing step of the same request (checkout -> plan change)
        }

        $lock = Cache::lock("billing:tenant:{$id}", (int) config('billing.lock_seconds', 15));

        if (! $lock->get()) {
            abort(409, __('Another billing change for your organisation is in progress. Please try again in a moment.'));
        }

        self::$held[$id] = 1;
        try {
            return $callback();
        } finally {
            unset(self::$held[$id]);
            $lock->release();
        }
    }
}
