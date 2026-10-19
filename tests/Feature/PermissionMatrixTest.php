<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * F11.4 — generated permission matrix. For every `api/*` route that names `permission:<slug>`, a signed-in user
 * holding NO permission at all must be refused with 403, whatever the verb. The list comes from the route table,
 * so a new gated route is covered the day it is added; a route that must stay reachable (self-service reads)
 * simply does not carry the middleware. Failures are collected so one run shows every offender.
 */
class PermissionMatrixTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    /** @return list<array{string, string, list<string>}> [method, uri, required slugs] */
    private function gatedRoutes(): array
    {
        $rows = [];

        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), 'api/') || str_contains($route->uri(), 'api/v1/')) {
                continue;
            }

            $middleware = array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware());
            $slugs = collect($middleware)->filter(fn ($m) => str_starts_with($m, 'permission:'))->map(fn ($m) => substr($m, 11))->values()->all();
            $platform = collect($middleware)->contains(fn ($m) => str_contains($m, 'super_admin') || str_starts_with($m, 'platform:'));

            if ($slugs !== [] && ! $platform) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $rows[] = [$method, '/'.preg_replace_callback('/\{([^}?]+)\??\}/', fn ($m) => $this->sample($route, $m[1]), $route->uri()), $slugs];
                }
            }
        }

        return $rows;
    }

    /** A value that satisfies the route's own constraint (`whereIn('type', [...])` is a regex alternation), else 1. */
    private function sample(LaravelRoute $route, string $param): string
    {
        $regex = $route->wheres[$param] ?? null;

        return $regex !== null && preg_match('/^[a-z_|]+$/i', $regex) ? explode('|', $regex)[0] : '1';
    }

    public function test_a_user_with_no_permissions_is_refused_on_every_gated_route(): void
    {
        $this->setAcmeModules(array_keys(config('subscriptions.module_meta', [])) ?: ['hrms.core']);
        $this->actAs($this->userWith([]));

        $routes = $this->gatedRoutes();
        $this->assertGreaterThan(100, count($routes), 'The matrix found suspiciously few gated routes.');

        $offenders = [];
        foreach ($routes as [$method, $uri, $slugs]) {
            $status = $this->json($method, $uri)->getStatusCode();
            if ($status !== 403) {
                $offenders[] = "$method $uri [".implode(',', $slugs)."] answered $status";
            }
        }

        $this->assertSame([], $offenders, "Gated routes reachable without their permission:\n".implode("\n", $offenders));
    }
}
