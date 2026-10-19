<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * F11.4 (first slice) — no API route is reachable without authentication unless it is on the reviewed
 * list below. Adding a public endpoint is allowed, but it has to be a conscious edit to this file.
 */
class RouteAuthAuditTest extends TestCase
{
    /** Public by design: sign-in, registration, health, public site forms and signed-by-provider webhooks. */
    private const PUBLIC = [
        'POST api/auth/2fa/challenge',
        'POST api/auth/forgot-password',
        'POST api/auth/login',
        'POST api/auth/reset-password',
        'POST api/contact',
        'POST api/csp-report',
        'GET api/health',
        'GET api/locales',
        'GET api/maintenance',
        'GET api/metrics',
        'GET api/signatures/verify/{code}',
        'GET api/calendar/{tenantId}/{token}',
        'POST api/integrations/github/{tenantId}',
        'GET api/sso/{slug}/callback',
        'GET api/sso/{slug}/info',
        'GET api/sso/{slug}/start',
        'POST api/logs/client',
        'POST api/register',
        'POST api/register/card',
        'POST api/register/complete',
        'GET api/register/options',
        'POST api/webhooks/razorpay',
        'POST api/webhooks/stripe',
    ];

    private const GUARDS = ['auth', 'api_token', 'signed'];

    /** @return array<string, LaravelRoute> */
    private function unguardedApiRoutes(): array
    {
        $open = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $middleware = array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware());
            $guarded = collect($middleware)->contains(fn (string $m) => in_array(explode(':', $m)[0], self::GUARDS, true) || str_contains($m, 'super_admin'));

            if (! $guarded) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $open["$method {$route->uri()}"] = $route;
                }
            }
        }

        return $open;
    }

    public function test_every_unauthenticated_api_route_is_on_the_reviewed_list(): void
    {
        $unexpected = array_diff(array_keys($this->unguardedApiRoutes()), self::PUBLIC);

        $this->assertSame([], array_values($unexpected), 'These API routes have no auth middleware. If they are meant to be public, add them to RouteAuthAuditTest::PUBLIC; otherwise put them behind auth.');
    }

    public function test_the_reviewed_list_has_no_stale_entries(): void
    {
        $stale = array_diff(self::PUBLIC, array_keys($this->unguardedApiRoutes()));

        $this->assertSame([], array_values($stale), 'Remove routes from RouteAuthAuditTest::PUBLIC that no longer exist or are now guarded.');
    }
}
