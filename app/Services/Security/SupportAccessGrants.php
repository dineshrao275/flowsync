<?php

namespace App\Services\Security;

use App\Models\PlatformSetting;
use App\Models\SupportAccessGrant;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Consent-based support access (P8.4). A tenant admin grants a window (read-only
 * by default); the platform may bind an impersonation to it. Impersonation
 * itself, its guard, expiry and audit are unchanged - this only adds the
 * tenant's consent as a precondition and a ceiling (mode, time).
 */
class SupportAccessGrants
{
    public const POLICY_KEY = 'require_support_consent';

    public const MAX_ACTIVE_PER_TENANT = 5;

    public function consentRequired(): bool
    {
        return PlatformSetting::bool(self::POLICY_KEY, false);
    }

    /**
     * @param  array{mode?: string, hours?: int, session_minutes?: int, note: string, support_ticket_id?: int|null}  $data
     */
    public function grant(Tenant $tenant, User $by, array $data): SupportAccessGrant
    {
        if (SupportAccessGrant::active()->where('tenant_id', $tenant->id)->count() >= self::MAX_ACTIVE_PER_TENANT) {
            throw ValidationException::withMessages(['form' => 'Too many active support grants. Revoke one first.']);
        }

        if (! empty($data['support_ticket_id'])
            && ! SupportTicket::where('id', $data['support_ticket_id'])->where('tenant_id', $tenant->id)->exists()) {
            throw ValidationException::withMessages(['support_ticket_id' => 'Unknown support ticket.']);
        }

        $maxHours = (int) config('tenancy.support_access.max_hours', 72);
        $hours = min(max(1, (int) ($data['hours'] ?? 24)), $maxHours);
        $session = min(max(5, (int) ($data['session_minutes'] ?? config('tenancy.impersonation.ttl_minutes', 30))), (int) config('tenancy.support_access.max_session_minutes', 120));

        return SupportAccessGrant::create([
            'tenant_id' => $tenant->id,
            'granted_by_user_id' => $by->id,
            'granted_by_name' => $by->name,
            'granted_by_email' => $by->email,
            'mode' => ($data['mode'] ?? 'read_only') === 'write' ? 'write' : 'read_only',
            'note' => $data['note'],
            'support_ticket_id' => $data['support_ticket_id'] ?? null,
            'expires_at' => now()->addHours($hours),
            'session_minutes' => $session,
        ]);
    }

    public function revoke(SupportAccessGrant $grant, User $by): void
    {
        if ($grant->revoked_at === null) {
            $grant->update(['revoked_at' => now(), 'revoked_by_name' => $by->name]);
        }
    }

    /**
     * Resolve the grant an impersonation start is bound to. Returns null only when
     * no grant was named AND consent is not required platform-wide.
     *
     * @throws ValidationException
     */
    public function resolveForStart(?int $grantId, Tenant $tenant, string $mode): ?SupportAccessGrant
    {
        if ($grantId === null) {
            if ($this->consentRequired()) {
                throw ValidationException::withMessages(['grant_id' => 'This tenant must grant support access before you can sign in as its users.']);
            }

            return null;
        }

        $grant = SupportAccessGrant::active()->where('tenant_id', $tenant->id)->find($grantId);
        if (! $grant) {
            throw ValidationException::withMessages(['grant_id' => 'That support access grant is not active for this tenant.']);
        }
        if ($mode === 'write' && $grant->mode !== 'write') {
            throw ValidationException::withMessages(['mode' => 'The tenant granted read-only access.']);
        }

        return $grant;
    }

    /** The session ends at the earliest of the default limit, the grant's window and its session length. */
    public function sessionExpiry(SupportAccessGrant $grant, Carbon $default): Carbon
    {
        return collect([$default, $grant->expires_at, now()->addMinutes($grant->session_minutes)])->min();
    }

    public function markUsed(SupportAccessGrant $grant): void
    {
        $grant->increment('uses');
        $grant->update(['last_used_at' => now()]);
    }
}
