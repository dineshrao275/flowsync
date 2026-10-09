<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * R9 — no `api/*` route may be reachable without an authorization decision.
 *
 * A route passes when ANY of these holds:
 *  - its middleware carries `permission:*`, `super_admin` or `signed`;
 *  - its action (or a same-class helper it calls) authorizes explicitly —
 *    `authorize()`, a Gate/Policy call, an `abort_*` on a role/permission, or
 *    one of the named require helpers;
 *  - it is on ALLOWED below, each with the reason it needs no decision.
 *
 * A new route that is none of those fails here, which is the point: the 2026-10
 * audit found four tenant routes (company profile, onboarding writes, payment
 * history) that answered any signed-in user, and nothing noticed.
 */
class RouteAuthorizationAuditTest extends TestCase
{
    /** @var array<string, string> action => why no authorization decision is needed */
    private const ALLOWED = [
        'AuthController@login' => 'public: credentials are the check',
        'AuthController@me' => 'returns the caller\'s own session payload',
        'ForgotPasswordController@create' => 'public, throttled, constant-time',
        'ResetPasswordController@update' => 'public: the reset token is the check',
        'RegisterController@store' => 'public: gated by the public_registration setting',
        'PlatformHealthController@ping' => 'public liveness ping, no data',
        'WebhookController@handleStripe' => 'signature verified in PaymentService',
        'WebhookController@handleRazorpay' => 'signature verified in PaymentService',
        'ImpersonationController@stop' => 'only meaningful inside an impersonation session',
        'ExportController@index' => 'self-scoped: user_id = caller',
        'ExportController@show' => 'self-scoped: user_id = caller',
        'OnboardingController@show' => 'read of the caller\'s own tenant wizard state',
        'ThemeController@show' => 'the caller\'s own theme',
    ];

    /** Calls that count as an explicit authorization decision. */
    private const DECISION = '/authorize\(|Gate::|->can\(|->cannot\(|requireTenantAdmin|authorize[A-Z]\w*\(|abort_unless|abort_if|hasRole\(|hasPermission\(|is_super_admin|isPlatformSuperAdmin|->granted\(|assertCan\w*\(|forUser\(/';

    public function test_every_api_route_has_an_authorization_decision(): void
    {
        $undecided = [];

        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            if ($this->guardedByMiddleware($route) || $this->decidesInAction($route)) {
                continue;
            }

            $action = $this->shortAction($route);
            if (! array_key_exists($action, self::ALLOWED)) {
                $undecided[] = implode(' ', $route->methods()).' '.$route->uri().'  ('.$action.')';
            }
        }

        $this->assertSame(
            [],
            $undecided,
            "These routes have no permission middleware and no authorization call:\n".implode("\n", $undecided),
        );
    }

    public function test_the_allowlist_has_no_stale_entries(): void
    {
        $actions = [];
        foreach (Route::getRoutes() as $route) {
            $actions[$this->shortAction($route)] = true;
        }

        foreach (array_keys(self::ALLOWED) as $action) {
            $this->assertArrayHasKey($action, $actions, "Allowlisted action no longer exists: {$action}");
        }
    }

    private function guardedByMiddleware(LaravelRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'permission:') || $middleware === 'super_admin' || str_starts_with($middleware, 'signed')) {
                return true;
            }
        }

        return false;
    }

    private function decidesInAction(LaravelRoute $route): bool
    {
        $uses = $route->getAction('uses');
        if (! is_string($uses) || ! str_contains($uses, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $uses);
        if (! method_exists($class, $method)) {
            return false;
        }

        $body = $this->body(new ReflectionMethod($class, $method));
        if (preg_match(self::DECISION, $body)) {
            return true;
        }

        // A same-class helper the action calls ($this->employee(...), $this->authorizeX()).
        if (preg_match_all('/\$this->(\w+)\(/', $body, $helpers)) {
            foreach (array_unique($helpers[1]) as $helper) {
                if ($helper !== $method && method_exists($class, $helper) && preg_match(self::DECISION, $this->body(new ReflectionMethod($class, $helper)))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function body(ReflectionMethod $method): string
    {
        $file = file($method->getFileName());

        return implode('', array_slice($file, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    private function shortAction(LaravelRoute $route): string
    {
        $uses = $route->getAction('uses');

        return is_string($uses) ? class_basename(explode('@', $uses)[0]).'@'.(explode('@', $uses)[1] ?? '') : 'Closure:'.$route->uri();
    }
}
