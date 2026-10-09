<?php

namespace Tests\Feature;

use App\Models\PlatformPermission;
use App\Models\PlatformRole;
use App\Models\SystemUser;
use App\Services\Security\PlatformAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P8.3 - platform personas narrowing the break-glass super admin, route by route. */
class PlatformAccessTest extends TestCase
{
    use IsolatesDatabase;

    private function personaUser(string $persona): SystemUser
    {
        $user = SystemUser::create([
            'name' => ucfirst($persona), 'email' => "{$persona}@flowsync.test", 'password' => 'password', 'is_super_admin' => true,
        ]);
        app(PlatformAccess::class)->forget();
        DB::connection('iso_system')->table('platform_user_role')->insert([
            'user_id' => $user->id, 'platform_role_id' => PlatformRole::where('slug', $persona)->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function signIn(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    public function test_the_catalog_and_four_personas_are_seeded_by_the_migration(): void
    {
        foreach (['billing_admin', 'support', 'auditor', 'operator'] as $slug) {
            $this->assertNotNull(PlatformRole::where('slug', $slug)->first(), $slug);
        }
        foreach (array_keys(config('platform_access.permissions')) as $slug) {
            $this->assertNotNull(PlatformPermission::where('slug', $slug)->first(), $slug);
        }
    }

    public function test_every_super_admin_route_maps_to_a_catalogued_permission(): void
    {
        $catalog = array_keys(config('platform_access.permissions'));
        $unmapped = [];

        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! in_array('super_admin', $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $verb) {
                $request = Request::create('/'.$route->uri(), $verb);
                $request->setRouteResolver(fn () => $route);
                $slug = app(PlatformAccess::class)->slugFor($request);
                if ($slug === null || ! in_array($slug, $catalog, true)) {
                    $unmapped[] = "{$verb} {$route->uri()}";
                }
            }
        }

        $this->assertSame([], $unmapped, "Platform routes without a catalogued permission:\n".implode("\n", $unmapped));
    }

    public function test_personas_only_use_catalogued_permissions(): void
    {
        $catalog = array_keys(config('platform_access.permissions'));
        foreach (config('platform_access.personas') as $slug => $persona) {
            $this->assertSame([], array_diff($persona['permissions'], $catalog), $slug);
        }
    }

    public function test_an_account_with_no_persona_stays_unrestricted(): void
    {
        $this->signIn('superadmin@flowsync.test');

        $this->getJson('/api/system/audit-logs')->assertOk();
        $this->getJson('/api/system/settings')->assertOk();
        $this->getJson('/api/auth/me')->assertJsonPath('user.platform.permissions', null);
    }

    public function test_billing_admin_manages_plans_but_not_audit_or_tenant_writes(): void
    {
        $this->personaUser('billing_admin');
        $this->signIn('billing_admin@flowsync.test');

        $this->getJson('/api/tenants')->assertOk();
        $this->getJson('/api/system/features')->assertOk();
        $this->getJson('/api/system/audit-logs')->assertForbidden()
            ->assertJsonPath('code', 'platform_permission_denied')->assertJsonPath('permission', 'audit.view');
        $this->postJson("/api/tenants/{$this->acme()->id}/suspend")->assertForbidden()->assertJsonPath('permission', 'tenants.manage');
        $this->putJson('/api/system/settings', ['maintenance_mode' => true])->assertForbidden();
        $this->postJson('/api/impersonate', [])->assertForbidden()->assertJsonPath('permission', 'impersonate.start');
    }

    public function test_auditor_reads_everything_and_changes_nothing(): void
    {
        $this->personaUser('auditor');
        $this->signIn('auditor@flowsync.test');

        $this->getJson('/api/system/audit-logs')->assertOk();
        $this->getJson('/api/system/settings')->assertOk();
        $this->getJson('/api/system/users')->assertOk();
        $this->putJson('/api/system/settings', ['maintenance_mode' => true])->assertForbidden();
        $this->postJson('/api/system/feature-flags', ['key' => 'x_flag'])->assertForbidden();
        $this->postJson("/api/tenants/{$this->acme()->id}/suspend")->assertForbidden();
    }

    public function test_support_can_work_tickets_and_impersonate_but_not_change_plans(): void
    {
        $this->personaUser('support');
        $this->signIn('support@flowsync.test');

        $this->getJson('/api/system/support/tickets')->assertOk();
        $this->postJson("/api/tenants/{$this->acme()->id}/subscription/cancel")->assertForbidden()->assertJsonPath('permission', 'billing.manage');
        // Reaches the controller (validation, not the persona gate): impersonate.start is held.
        $this->postJson('/api/impersonate', [])->assertStatus(422);
    }

    public function test_the_explicit_platform_middleware_checks_the_slug(): void
    {
        Route::middleware(['web', 'switch_tenant', 'auth', 'tenant', 'platform:flags.manage'])
            ->get('/api/_probe/platform', fn () => response()->json(['ok' => true]));

        $this->personaUser('auditor');
        $this->signIn('auditor@flowsync.test');
        $this->getJson('/api/_probe/platform')->assertForbidden();

        $this->personaUser('operator');
        $this->signIn('operator@flowsync.test');
        $this->getJson('/api/_probe/platform')->assertOk();
    }

    public function test_assigning_personas_is_audited_and_protects_the_last_break_glass_admin(): void
    {
        $this->signIn('superadmin@flowsync.test');
        $second = SystemUser::create(['name' => 'Second', 'email' => 'second@flowsync.test', 'password' => 'password', 'is_super_admin' => true]);
        $root = $this->systemUser('superadmin@flowsync.test');

        // The only other unrestricted admin may be narrowed while the root stays unrestricted.
        $this->putJson("/api/system/users/{$second->id}/platform-roles", ['roles' => ['auditor']])->assertOk()
            ->assertJsonPath('platform_roles.0', 'auditor');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.roles_assigned']);

        // Narrowing the last unrestricted admin is refused.
        $this->putJson("/api/system/users/{$root->id}/platform-roles", ['roles' => ['auditor']])->assertStatus(422);
        $this->putJson("/api/system/users/{$second->id}/platform-roles", ['roles' => ['nope']])->assertStatus(422);

        // Empty list restores break-glass.
        $this->putJson("/api/system/users/{$second->id}/platform-roles", ['roles' => []])->assertOk();
        $this->getJson('/api/system/users')->assertOk()->assertJsonFragment(['platform_roles' => []]);
    }

    public function test_personas_cannot_assign_personas_and_tenant_users_cannot_see_the_catalog(): void
    {
        $this->personaUser('operator');
        $this->signIn('operator@flowsync.test');
        $root = $this->systemUser('superadmin@flowsync.test');
        $this->putJson("/api/system/users/{$root->id}/platform-roles", ['roles' => ['auditor']])->assertForbidden();
        $this->getJson('/api/system/platform-roles')->assertForbidden();

        $this->postJson('/api/auth/logout');
        $this->loginAs('admin@flowsync.test');
        $this->getJson('/api/system/platform-roles')->assertForbidden();
    }
}
