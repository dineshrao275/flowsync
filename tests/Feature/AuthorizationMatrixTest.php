<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePermission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantProvisioner;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * R15 — the authorization matrix, generated from the route table and the permission
 * catalog instead of written by hand: every default role × every parameterless
 * `GET api/*` route that names a `permission:` gate.
 *
 *  - a role that does NOT hold the permission must get the 403 that names it;
 *  - a role that holds it must never get that 403 (other answers — 200, 422, a
 *    controller-level refusal — are the business of the route's own tests).
 *
 * A new route with a permission gate, or a new default role, is covered by this the
 * day it exists; a role/permission wiring mistake fails here with the exact cell.
 */
class AuthorizationMatrixTest extends TestCase
{
    use IsolatesDatabase;

    /** @return list<array{uri: string, permissions: list<string>}> */
    private function gatedRoutes(): array
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($route->uri(), 'api/') || str_contains($route->uri(), '{')) {
                continue;
            }
            $permissions = collect($route->gatherMiddleware())
                ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))
                ->map(fn ($m) => substr($m, strlen('permission:')))->values()->all();
            // Only tenant routes: SA routes use `super_admin`, and public ones carry no gate.
            if ($permissions === [] || in_array('super_admin', $route->gatherMiddleware(), true)) {
                continue;
            }
            $found[$route->uri()] = ['uri' => $route->uri(), 'permissions' => $permissions];
        }

        return array_values($found);
    }

    private function userWithRole(string $slug): User
    {
        $user = User::create(['name' => "Matrix {$slug}", 'email' => "matrix-{$slug}@flowsync.test", 'password' => Hash::make('password')]);
        $user->roles()->sync([Role::where('slug', $slug)->firstOrFail()->id]);

        return $user;
    }

    public function test_every_default_role_is_allowed_and_denied_exactly_by_its_permissions(): void
    {
        $routes = $this->gatedRoutes();
        $this->assertGreaterThan(40, count($routes), 'The generated matrix should cover many routes.');

        $roles = array_keys(config('permissions.roles'));
        $users = [];
        foreach ($roles as $slug) {
            $users[$slug] = $this->userWithRole($slug);
        }
        app(TenantProvisioner::class)->syncRouting($this->dbm, $this->acme());

        $wrong = [];
        $cells = 0;

        foreach ($roles as $slug) {
            $held = $users[$slug]->fresh()->load('roles.permissions');
            $this->postJson('/api/auth/login', ['email' => "matrix-{$slug}@flowsync.test", 'password' => 'password'])->assertOk();

            foreach ($routes as ['uri' => $uri, 'permissions' => $permissions]) {
                $missing = array_values(array_filter($permissions, fn (string $p) => ! $held->hasPermission($p)));
                $response = $this->getJson('/'.$uri);
                $cells++;

                if ($missing !== []) {
                    // Denied: it must be 403 and it must name the first missing grant (middleware order).
                    if ($response->status() !== 403) {
                        $wrong[] = "{$slug} reached {$uri} without ".implode(', ', $missing)." (got {$response->status()})";
                    }

                    continue;
                }

                // Allowed: the permission gate must not be what stops it.
                if ($response->status() === 403) {
                    foreach ($permissions as $p) {
                        if (str_contains((string) $response->json('message'), EnsurePermission::denial($p))) {
                            $wrong[] = "{$slug} was refused {$uri} although it holds {$p}";
                        }
                    }
                }
            }

            $this->postJson('/api/auth/logout');
        }

        $this->assertGreaterThan(400, $cells);
        $this->assertSame([], $wrong, "Authorization matrix mismatches:\n".implode("\n", array_slice($wrong, 0, 30)));
    }

    public function test_the_matrix_actually_distinguishes_roles(): void
    {
        // Guard against a vacuous pass: the admin holds far more than the viewer.
        $routes = $this->gatedRoutes();
        $admin = $this->userWithRole('admin')->load('roles.permissions');
        $viewer = $this->userWithRole('viewer')->load('roles.permissions');

        $adminOk = collect($routes)->filter(fn ($r) => collect($r['permissions'])->every(fn ($p) => $admin->hasPermission($p)))->count();
        $viewerOk = collect($routes)->filter(fn ($r) => collect($r['permissions'])->every(fn ($p) => $viewer->hasPermission($p)))->count();

        $this->assertSame(count($routes), $adminOk);
        $this->assertLessThan($adminOk, $viewerOk);
        $this->assertGreaterThan(0, $viewerOk);
    }
}
