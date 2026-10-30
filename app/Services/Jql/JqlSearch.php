<?php

declare(strict_types=1);

namespace App\Services\Jql;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The one entry point the task endpoints use to apply a `jql` query string to an already-scoped
 * task query: checks the plan, parses, compiles, and turns every problem into a 422 on `jql` so
 * the UI can show the message next to the box.
 */
final class JqlSearch
{
    public static function enabled(): bool
    {
        $tenantId = app(TenantContext::class)->currentId();

        if ($tenantId === null) {
            return false;
        }

        $tenant = Tenant::query()->find($tenantId);

        return $tenant !== null && app(TenantLimits::class)->hasModule($tenant, 'jql');
    }

    public static function apply(Builder $query, string $jql, User $user): Builder
    {
        if (! self::enabled()) {
            throw ValidationException::withMessages(['jql' => __('Advanced search is not available on your plan.')]);
        }

        try {
            return (new JqlCompiler($user))->apply($query, JqlParser::parse($jql));
        } catch (JqlException $e) {
            throw ValidationException::withMessages(['jql' => $e->getMessage()]);
        }
    }
}
