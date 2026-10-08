<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\AuditMask;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Central `audit_logs` writer for platform (super admin) events.
 *
 * Three shapes, one ledger:
 *  - `record()` — flat event payload, masked by the shared HRMS rules;
 *  - `diff()`   — before/after snapshots reduced to the keys that actually
 *                 changed, then masked (H-7: the feed answers "what changed",
 *                 without carrying raw values it has no business carrying);
 *  - `auth()`   — identity records (login/logout): the account IS the record,
 *                 so only secrets are masked — an `auth.login` row that read
 *
 *                 `j***@***` could not answer who signed in.
 *
 * `actor_id` is a FK to the central `users`, so only a super admin session
 * may fill it; a tenant user (or an impersonated one) is never a central row
 * and lands `null`, with the identity kept in `data`.
 */
class PlatformAudit
{
    /**
     * Write a flat event row.
     *
     * @param  array<string, mixed>  $data
     * @param  int|null  $actorId  explicit actor for jobs/services that hold a
     *                             typed central user (the request is absent there)
     */
    public function record(
        ?Request $request,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        array $data,
        ?int $actorId = null,
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'data' => AuditMask::mask($data),
            'actor_id' => $actorId ?? $this->actorFrom($request),
            'ip_address' => $ipAddress ?? $request?->ip() ?? request()->ip(),
        ]);
    }

    /**
     * Write a before/after row: only the keys that changed travel, masked.
     *
     * `$context` carries caller-curated metadata that must survive even when
     * unchanged (the changed-key list the UI renders, the module a toggle
     * touched, the slug a page is known by).
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    public function diff(
        ?Request $request,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        ?array $before,
        ?array $after,
        array $context = [],
    ): AuditLog {
        $data = $context;
        $changed = $this->changedKeys($before, $after);

        if ($changed !== []) {
            $data['before'] = $before === null ? null : Arr::only($before, $changed);
            $data['after'] = $after === null ? null : Arr::only($after, $changed);
        }

        return $this->record($request, $action, $subjectType, $subjectId, $data);
    }

    /**
     * Write an identity row (login/logout): secrets masked, account kept.
     *
     * `user_id` in the payload is also lifted onto the row's `subject_*`
     * columns so the feed's Target column points at the account. `$actorId`
     * overrides the request-derived actor for the caller that has already
     * torn down its authenticated state (logout).
     *
     * @param  array<string, mixed>  $data
     */
    public function auth(?Request $request, string $action, array $data, ?int $actorId = null): AuditLog
    {
        return AuditLog::create([
            'subject_type' => 'users',
            'subject_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'action' => $action,
            'data' => AuditMask::maskSecrets($data),
            'actor_id' => $actorId ?? $this->actorFrom($request),
            'ip_address' => $request?->ip() ?? request()->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array<int, string>
     */
    private function changedKeys(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];

        $keys = array_unique([...array_keys($before), ...array_keys($after)]);

        return array_values(array_filter(
            $keys,
            fn ($key) => ($before[$key] ?? null) !== ($after[$key] ?? null),
        ));
    }

    private function actorFrom(?Request $request): ?int
    {
        $user = $request?->user();

        return $user !== null && $user->is_super_admin ? (int) $user->id : null;
    }
}
