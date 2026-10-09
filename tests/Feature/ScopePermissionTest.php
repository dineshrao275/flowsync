<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionScope;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase B of the member-access plan: the own/assigned/all scope variants.
 *
 * Nothing here enforces a scope yet (policies migrate in Phase C) — these
 * tests pin the catalog that every later phase reads, and the resolution
 * rule that decides whether a grant answers a scoped check.
 */
class ScopePermissionTest extends TestCase
{
    use IsolatesDatabase;

    public function test_every_declared_domain_gets_all_three_scope_variants(): void
    {
        $catalog = collect(config('permissions.permissions'))->pluck('slug');

        foreach (config('permissions.scopes') as $domain => $verbs) {
            foreach ($verbs as $verb) {
                foreach (PermissionScope::SCOPES as $scope) {
                    $this->assertContains(
                        "{$domain}.{$verb}_{$scope}",
                        $catalog,
                        "Missing generated scope variant {$domain}.{$verb}_{$scope}",
                    );
                }
            }
        }

        // The generator must never shadow a hand-written entry: payroll's
        // `_all` predates the generator and carries its own SENSITIVE marking.
        $this->assertSame(1, $catalog->filter(fn (string $slug) => $slug === 'hrms.payroll.view_all')->count());
        $this->assertSame($catalog->count(), $catalog->unique()->count(), 'Duplicate slug in catalog');
    }

    public function test_the_generator_skips_only_declared_slugs(): void
    {
        $slugs = array_column(config('permissions.permissions'), 'slug');

        // 62 base entries + 13 domains x 3 scopes - 1 hand-written duplicate.
        $this->assertCount(62 + 13 * 3 - 1, $slugs);
    }

    public function test_a_wider_scope_satisfies_a_narrower_check(): void
    {
        $satisfies = fn (string $request, string $grant): bool => in_array(
            $grant,
            PermissionScope::satisfying($request),
            true,
        );

        // own ⊂ assigned ⊂ all, so a wider grant answers a narrower request.
        $this->assertTrue($satisfies('hrms.leave.view_own', 'hrms.leave.view_assigned'));
        $this->assertTrue($satisfies('hrms.leave.view_own', 'hrms.leave.view_all'));
        $this->assertTrue($satisfies('hrms.leave.view_assigned', 'hrms.leave.view_all'));

        // …and never the other way round.
        $this->assertFalse($satisfies('hrms.leave.view_all', 'hrms.leave.view_own'));
        $this->assertFalse($satisfies('hrms.leave.view_assigned', 'hrms.leave.view_own'));

        // A narrower grant never leaks across verbs: viewing is not managing.
        $this->assertFalse($satisfies('hrms.leave.view_all', 'hrms.leave.manage'));
        $this->assertFalse($satisfies('hrms.attendance.view_own', 'hrms.attendance.view_sensitive'));
    }

    public function test_the_legacy_slug_answers_every_scope_by_default(): void
    {
        $satisfying = PermissionScope::satisfying('hrms.leave.view_all');

        $this->assertContains('hrms.leave.view', $satisfying);

        // A grant of the legacy slug therefore still reads as "all rows" —
        // no existing role narrows until an admin says so.
        $this->assertSame('all', PermissionScope::legacyScope('hrms.leave.view'));
    }

    public function test_the_payroll_legacy_slug_answers_own_only(): void
    {
        // `hrms.payroll.view` has always meant "runs + my own payslip": the
        // alias keeps a holder from starting to read everyone else's payslip.
        $this->assertSame('own', PermissionScope::legacyScope('hrms.payroll.view'));

        $this->assertContains('hrms.payroll.view', PermissionScope::satisfying('hrms.payroll.view_own'));
        $this->assertNotContains('hrms.payroll.view', PermissionScope::satisfying('hrms.payroll.view_all'));
    }

    public function test_suffixes_that_are_not_scopes_resolve_exactly(): void
    {
        foreach (['hrms.documents.view_sensitive', 'hrms.leave.view', 'hrms.payroll.run', 'dashboard.view'] as $slug) {
            $this->assertNull(PermissionScope::parse($slug), "{$slug} must not parse as a scope");
            $this->assertSame([$slug], PermissionScope::satisfying($slug));
        }
    }

    public function test_granted_answers_with_the_scopes_the_role_actually_holds(): void
    {
        $own = $this->userWith(['hrms.leave.view_own']);

        $this->assertTrue($own->granted('hrms.leave.view_own'));
        $this->assertFalse($own->granted('hrms.leave.view_assigned'));
        $this->assertFalse($own->granted('hrms.leave.view_all'));

        $team = $this->userWith(['hrms.leave.view_assigned']);

        $this->assertTrue($team->granted('hrms.leave.view_own'));
        $this->assertTrue($team->granted('hrms.leave.view_assigned'));
        $this->assertFalse($team->granted('hrms.leave.view_all'));

        $legacy = $this->userWith(['hrms.leave.view']);

        $this->assertTrue($legacy->granted('hrms.leave.view_own'));
        $this->assertTrue($legacy->granted('hrms.leave.view_all'));

        $payroll = $this->userWith(['hrms.payroll.view']);

        $this->assertTrue($payroll->granted('hrms.payroll.view_own'));
        $this->assertFalse($payroll->granted('hrms.payroll.view_all'));
    }

    public function test_a_provisioned_tenant_carries_every_scope_variant(): void
    {
        $this->connectTenant('acme');

        $this->assertSame(
            count(config('permissions.permissions')),
            Permission::count(),
        );
        $this->assertTrue(Permission::where('slug', 'hrms.leave.view_own')->exists());
        $this->assertTrue(Permission::where('slug', 'hrms.leave.view_all')->exists());

        // The admin role is the '*' selector, so it holds the whole catalog —
        // variants included — without a single literal slug being listed.
        $admin = Role::where('slug', 'admin')->firstOrFail();
        $this->assertTrue($admin->permissions->contains('slug', 'hrms.leave.view_own'));
    }

    public function test_default_roles_stop_at_the_scopes_they_declare(): void
    {
        $viewer = $this->userWithRole('viewer');
        $editor = $this->userWithRole('editor');
        $manager = $this->userWithRole('manager');
        $hrManager = $this->userWithRole('hr_manager');

        // viewer/editor: own rows only, and never the legacy slug — which is
        // the one that reads as "every row in the tenant".
        foreach ([$viewer, $editor] as $user) {
            $this->assertTrue($user->granted('hrms.leave.view_own'), $user->email);
            $this->assertFalse($user->granted('hrms.leave.view_assigned'), $user->email);
            $this->assertFalse($user->granted('hrms.leave.view_all'), $user->email);
            $this->assertFalse($user->hasPermission('hrms.leave.view'), $user->email);
        }

        // manager: down the reporting line and no further.
        $this->assertTrue($manager->granted('hrms.leave.view_assigned'));
        $this->assertFalse($manager->granted('hrms.leave.view_all'));
        $this->assertFalse($manager->hasPermission('hrms.leave.view'));

        // Payroll, talent, documents and engagement are exempt: a manager
        // reads their own and nothing of a report's.
        $this->assertTrue($manager->granted('hrms.payroll.view_own'));
        $this->assertFalse($manager->granted('hrms.payroll.view_assigned'));

        // hr_manager still holds the legacy grant through the `hrms.*`
        // selector, so it keeps reading the whole tenant.
        $this->assertTrue($hrManager->granted('hrms.leave.view_all'));
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $user = User::create([
            'name' => "Scope User {$sequence}",
            'email' => "scope.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Scope Role {$sequence}",
            'slug' => "scope-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function userWithRole(string $roleSlug): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $role = Role::where('slug', $roleSlug)->firstOrFail();

        $user = User::create([
            'name' => "Scoped {$roleSlug} {$sequence}",
            'email' => "scope.{$roleSlug}.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
