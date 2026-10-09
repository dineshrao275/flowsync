<?php

namespace App\Support;

use App\Models\ImpersonationLog;
use App\Models\SupportAccessGrant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Server-side limits on a super admin acting as a tenant user (R7/R8).
 *
 *  - Time box: a session past `expires_at` is ended on its next request (and by
 *    `tenants:close-impersonations` if nobody touches it again). A session that
 *    predates the time box has no expiry and is ended too — re-entering costs
 *    one reason string, leaving it open costs a standing hole.
 *  - Read-only by default: any non-safe verb is refused unless the session was
 *    opened with `mode = write`.
 *  - Even in write mode, the actions that change who has access or what the
 *    tenant pays are refused: roles, users, billing, exports, the company
 *    profile, onboarding. Support may look, and fix data; it may not mint
 *    access or move money inside someone else's account.
 *
 * The pieces a session needs to END (stop, logout, broadcast channel auth) are
 * always allowed.
 */
final class ImpersonationGuard
{
    /** Paths a session may POST to in any mode. */
    private const ALWAYS_ALLOWED = ['api/impersonate/stop', 'api/auth/logout', 'broadcasting/auth'];

    /** Paths refused for writes even in `write` mode. */
    private const BLOCKED_WHEN_WRITING = [
        'api/roles', 'api/roles/*',
        'api/project-roles', 'api/project-roles/*',
        'api/users', 'api/users/*',
        'api/my-subscription/*',
        'api/billing/*',
        'api/support/*',
        'api/api-tokens',
        'api/api-tokens/*',
        'api/webhooks',
        'api/webhooks/*',
        'api/my-export', 'api/my-export/*',
        'api/tenant/profile',
        'api/onboarding/*',
        'api/impersonate',
        'api/auth/2fa', 'api/auth/2fa/*',
        'api/security/*',
        'api/support-access', 'api/support-access/*',
    ];

    /**
     * @param  array<string, mixed>  $impersonation  the `impersonate` session value
     *
     * @throws HttpResponseException
     */
    public function enforce(Request $request, array $impersonation): void
    {
        $expiresAt = $impersonation['expires_at'] ?? null;

        if ($expiresAt === null || (int) $expiresAt <= now()->timestamp) {
            $this->end($request, $impersonation);

            throw new HttpResponseException(response()->json([
                'message' => 'Your impersonation session has expired. Sign in again to continue.',
                'code' => 'impersonation_expired',
            ], 401));
        }

        // P8.4: a session bound to a tenant's consent ends the moment that consent is revoked.
        if (! empty($impersonation['grant_id']) && ! SupportAccessGrant::active()->whereKey($impersonation['grant_id'])->exists()) {
            $this->end($request, $impersonation);

            throw new HttpResponseException(response()->json([
                'message' => 'The tenant withdrew support access. Your session has ended.',
                'code' => 'support_access_revoked',
            ], 401));
        }

        if ($request->isMethodSafe() || $request->is(...self::ALWAYS_ALLOWED)) {
            return;
        }

        if (($impersonation['mode'] ?? 'read_only') !== 'write') {
            throw new HttpResponseException(response()->json([
                'message' => 'This impersonation session is read-only. Start a new session with changes enabled to do this.',
                'code' => 'impersonation_read_only',
            ], 403));
        }

        if ($request->is(...self::BLOCKED_WHEN_WRITING)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This action cannot be performed while impersonating a user.',
                'code' => 'impersonation_blocked',
            ], 403));
        }
    }

    /**
     * Close the log (ended at the moment it lapsed, not when it was noticed) and
     * drop the whole session, which signs the super admin out.
     *
     * @param  array<string, mixed>  $impersonation
     */
    private function end(Request $request, array $impersonation): void
    {
        $log = isset($impersonation['log_id']) ? ImpersonationLog::find($impersonation['log_id']) : null;

        if ($log && $log->ended_at === null) {
            $log->update(['ended_at' => $log->expires_at ?? now()]);
        }

        $request->session()->invalidate();
    }
}
