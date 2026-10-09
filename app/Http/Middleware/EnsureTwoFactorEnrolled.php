<?php

namespace App\Http\Middleware;

use App\Services\Security\TwoFactorLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce-per-role (P8.1): a session that signed in without a second factor
 * although its role requires one (flag set by TwoFactorLogin) may only reach
 * enrolment, `me` and logout until it enrols. Session-only check - no queries.
 * Appended to the `web` group; the flag is cleared on a successful confirm.
 */
class EnsureTwoFactorEnrolled
{
    private const ALLOWED = ['api/auth/me', 'api/auth/logout', 'api/auth/2fa', 'api/auth/2fa/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->get(TwoFactorLogin::ENROLL_KEY) && ! $request->is(...self::ALLOWED)) {
            return response()->json([
                'message' => 'Set up two-factor authentication to continue.',
                'code' => 'two_factor_enrollment_required',
            ], 403);
        }

        return $next($request);
    }
}
