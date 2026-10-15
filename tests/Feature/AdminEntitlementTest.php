<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * FB-1 — a tenant admin gets everything the tenant's plan includes and nothing it
 * does not: permissions never hide a subscribed module (the admin role is `*`,
 * even when its stored snapshot lags the catalog), and a missing module is a
 * fail-closed 403 from the server.
 */
class AdminEntitlementTest extends TestCase
{
    use IsolatesDatabase;

    /** @return array<string, array{0: string, 1: list<string>}> module => [uri, all modules that route needs] */
    private function gatedGetRoutes(): array
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($route->uri(), 'api/') || str_contains($route->uri(), '{')) {
                continue;
            }
            $modules = collect($route->gatherMiddleware())
                ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'ensure_module:'))
                ->map(fn ($m) => substr($m, strlen('ensure_module:')))->values()->all();
            if ($modules === []) {
                continue;
            }
            $key = implode('+', $modules);
            $found[$key] ??= [$route->uri(), $modules];
        }

        return $found;
    }

    private function planWith(array $modules): void
    {
        $plan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => [...$plan->limits, 'modules' => $modules]]);
        app(SubscriptionService::class)->assign(Tenant::where('slug', 'acme')->firstOrFail(), $plan->fresh());
    }

    public function test_the_admin_reaches_every_module_the_plan_includes_even_with_a_stale_role_snapshot(): void
    {
        $routes = $this->gatedGetRoutes();
        $this->assertGreaterThan(10, count($routes), 'Expected many module-gated routes.');

        // Simulate a role provisioned before most permissions existed.
        Role::where('slug', 'admin')->firstOrFail()->permissions()->sync([]);

        $all = collect($routes)->pluck(1)->flatten()->unique()->values()->all();
        $this->planWith($all);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $denied = [];
        foreach ($routes as [$uri]) {
            if ($this->getJson('/'.$uri)->status() === 403) {
                $denied[] = $uri;
            }
        }

        $this->assertSame([], $denied, "A tenant admin was refused routes their plan includes:\n".implode("\n", $denied));
    }

    public function test_a_module_the_plan_lacks_is_refused_with_its_reason(): void
    {
        $routes = $this->gatedGetRoutes();
        $this->planWith(['time_tracking']);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $leaks = [];
        foreach ($routes as $key => [$uri, $modules]) {
            if (array_diff($modules, ['time_tracking']) === []) {
                continue; // routes this plan does include
            }
            $response = $this->getJson('/'.$uri);
            if ($response->status() !== 403 || ! $response->headers->has('X-Module-Reason')) {
                $leaks[] = "{$uri} ({$key}) => ".$response->status();
            }
        }

        $this->assertSame([], $leaks, "Routes reachable without their module:\n".implode("\n", $leaks));
    }
}
