<?php

namespace Tests\Feature;

use App\Models\Hrms\Org\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P3.3 — the org HTTP surface.
 *
 * The service rules are covered in `HrmsOrgServiceTest`. What is worth
 * protecting *here* is the part a service cannot see: that the two gates
 * (`hrms.core` for the module, `hrms.view` for the surface) and then the
 * `hrms.org.view` / `hrms.org.manage` policy split decide who reaches what, that
 * the whole org arrives in one request, and that the routes are shaped so a
 * `reorder` is not swallowed by a `{resource}` placeholder.
 */
class HrmsOrgApiTest extends TestCase
{
    use IsolatesDatabase;

    // ------------------------------------------------------------- access

    public function test_a_tenant_without_the_hrms_module_has_no_org_surface(): void
    {
        $this->setAcmeModules([]);
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $this->getJson('/api/hrms/org')->assertForbidden();
        $this->getJson('/api/hrms/departments')->assertForbidden();
    }

    public function test_a_user_without_the_hrms_permission_is_refused(): void
    {
        $this->actAs($this->userWith(['hrms.org.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $this->getJson('/api/hrms/org')->assertForbidden();
        $this->postJson('/api/hrms/departments', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_a_user_without_the_org_permission_is_refused(): void
    {
        // `hrms.view` opens the module; it must not open the org records inside
        // it, or the employee permission would silently grant the whole chart.
        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson('/api/hrms/org')->assertForbidden();
    }

    public function test_a_reader_may_read_but_not_write(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view']));

        $this->getJson('/api/hrms/departments')->assertOk();
        $this->getJson('/api/hrms/designations')->assertOk();
        $this->getJson('/api/hrms/locations')->assertOk();

        $this->postJson('/api/hrms/departments', ['name' => 'Denied'])->assertForbidden();
        $this->postJson('/api/hrms/designations', ['name' => 'Denied'])->assertForbidden();
        $this->postJson('/api/hrms/locations', ['name' => 'Denied'])->assertForbidden();
    }

    public function test_a_manager_may_write(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $this->postJson('/api/hrms/departments', ['name' => 'Engineering'])
            ->assertCreated()
            ->assertJsonPath('department.name', 'Engineering');

        // `hrms.org.manage` implies nothing about reading, but the index needs
        // `viewAny`; a manager who cannot list what they just created is not a
        // usable role combination, so both are granted in the seeded admin role.
        $this->assertDatabaseHas('departments', ['name' => 'Engineering']);
    }

    public function test_a_manager_cannot_bypass_the_policy_by_writing_only(): void
    {
        // Deliberately *no* `hrms.org.view`: the whole point is that writing is
        // granted and reading that record back is not.
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $created = $this->postJson('/api/hrms/departments', ['name' => 'Engineering'])
            ->assertCreated()
            ->json('department.id');

        // Writing is granted; reading that same record back is `view`, and a
        // policy that answered every method with "has manage" would pass this
        // test and quietly widen the reader set.
        $this->getJson("/api/hrms/departments/{$created}")->assertForbidden();
    }

    // ------------------------------------------------------------ the org page

    public function test_the_whole_org_arrives_in_one_request(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage']));

        $parent = $this->postJson('/api/hrms/departments', ['name' => 'Company'])
            ->assertCreated()->json('department.id');
        $this->postJson('/api/hrms/departments', ['name' => 'Platform', 'parent_id' => $parent])
            ->assertCreated();
        $this->postJson('/api/hrms/designations', ['name' => 'Engineer', 'level' => 3])->assertCreated();
        $this->postJson('/api/hrms/locations', ['name' => 'Headquarters', 'city' => 'Pune'])
            ->assertCreated();

        $response = $this->getJson('/api/hrms/org')
            ->assertOk()
            ->assertJsonStructure([
                'tree' => [['id', 'name', 'depth', 'headcount', 'direct_count', 'departments']],
                'departments' => [['id', 'name', 'slug', 'employees_count', 'children_count']],
                'designations' => [['id', 'name', 'level']],
                'locations' => [['id', 'name', 'city']],
            ]);

        // The chart is nested, not a flat list the client has to rebuild, and
        // it carries **the whole tenant** in that one request — including the
        // departments a tenant is seeded with (P3.5) and the ones this test
        // created. Asserting the row count is what makes "the whole org" a
        // claim: a payload that silently dropped the starters would still
        // contain Company and Platform.
        $names = collect($response->json('tree'))
            ->flatMap(fn (array $node): array => $this->flatten($node))
            ->pluck('name')
            ->all();

        $this->assertContains('Company', $names);
        $this->assertContains('Platform', $names);
        $this->assertCount(Department::query()->count(), $names);

        $company = collect($response->json('tree'))->firstWhere('name', 'Company');
        $this->assertNotNull($company, 'a nested chart is what carries the parent/child edge');
        $this->assertSame(['Platform'], collect($company['departments'])->pluck('name')->all());
    }

    public function test_the_chart_carries_both_headcounts(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $parent = $this->postJson('/api/hrms/departments', ['name' => 'Company'])
            ->assertCreated()->json('department.id');
        $child = $this->postJson('/api/hrms/departments', ['name' => 'Platform', 'parent_id' => $parent])
            ->assertCreated()->json('department.id');

        $this->postJson('/api/hrms/employees', [
            'name' => 'One Person',
            'department_id' => $child,
        ])->assertCreated();

        $tree = $this->getJson('/api/hrms/org')->assertOk()->json('tree');

        // By id, not by index — the tenant also carries seeded departments, so
        // `$tree[0]` is whichever row sorts first and this test's own
        // department only happened to be it.
        $node = collect($tree)->firstWhere('id', $parent);

        $this->assertSame(1, $node['headcount'], 'the subtree total');
        $this->assertSame(0, $node['direct_count'], 'this node alone');
        $this->assertSame(1, $node['departments'][0]['direct_count']);
    }

    public function test_a_department_show_carries_its_path_root_first(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $root = $this->postJson('/api/hrms/departments', ['name' => 'Company'])
            ->assertCreated()->json('department.id');
        $middle = $this->postJson('/api/hrms/departments', ['name' => 'Engineering', 'parent_id' => $root])
            ->assertCreated()->json('department.id');
        $leaf = $this->postJson('/api/hrms/departments', ['name' => 'Platform', 'parent_id' => $middle])
            ->assertCreated()->json('department.id');

        $this->getJson("/api/hrms/departments/{$leaf}")
            ->assertOk()
            ->assertJsonPath('department.name', 'Platform')
            ->assertJsonPath('path.0.name', 'Company')
            ->assertJsonPath('path.2.name', 'Platform');
    }

    // ------------------------------------------------------------ department writes

    public function test_a_duplicate_department_name_gets_a_suffixed_slug(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        // A name the tenant does not already have. The collision against the
        // seeded "Engineering" is its own test below, because it asserts a
        // different thing: that a starter's name is not up for grabs.
        $this->postJson('/api/hrms/departments', ['name' => 'Widgets'])
            ->assertCreated()
            ->assertJsonPath('department.slug', 'widgets');

        $this->postJson('/api/hrms/departments', ['name' => 'Widgets'])
            ->assertCreated()
            ->assertJsonPath('department.slug', 'widgets-2');
    }

    /**
     * A tenant is provisioned with a starter "Engineering" (P3.5), so the name
     * a new department is most likely to be given is already taken.
     *
     * The starter must survive the attempt unchanged — a create that reused the
     * existing row's slug would either 500 on the unique index or, worse, leave
     * the tenant with one department where it typed two.
     */
    public function test_a_seeded_department_name_is_not_up_for_grabs(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $before = Department::query()->where('slug', 'engineering')->firstOrFail();

        $created = $this->postJson('/api/hrms/departments', ['name' => 'Engineering'])
            ->assertCreated()
            ->json('department');

        $this->assertNotSame('engineering', $created['slug'], 'the starter already holds that slug');
        $this->assertNotSame($before->id, $created['id'], 'a new row, not the starter re-pointed');

        // And the starter is exactly as it was.
        $this->assertTrue($before->refresh()->is($before));
        $this->assertSame('Engineering', $before->name);
        $this->assertSame('engineering', $before->slug);
    }

    public function test_a_client_supplied_slug_is_not_accepted(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        // Silently ignored would be worse than refused: a client that sends a
        // slug and gets a different one back has no way to learn its own
        // choice was dropped, and the next rename collides with what it thinks
        // it stored.
        $this->postJson('/api/hrms/departments', ['name' => 'Widgets', 'slug' => 'mine'])
            ->assertCreated()
            ->assertJsonPath('department.slug', 'widgets');
    }

    public function test_a_cyclic_parent_is_refused(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $parent = $this->postJson('/api/hrms/departments', ['name' => 'Engineering'])
            ->assertCreated()->json('department.id');
        $child = $this->postJson('/api/hrms/departments', ['name' => 'Platform', 'parent_id' => $parent])
            ->assertCreated()->json('department.id');

        $this->putJson("/api/hrms/departments/{$parent}", ['parent_id' => $child])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_an_exited_employee_cannot_head_a_department(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $employee = $this->postJson('/api/hrms/employees', ['name' => 'Leaver'])->assertCreated();
        $this->postJson("/api/hrms/employees/{$employee->json('employee.id')}/terminate", [
            'reason' => 'resigned',
        ])->assertOk();

        // Keyed to the field, not to `form`: the head picker is what has to
        // show this error, whereas a structural rule (a cycle, a record in use)
        // has no single field to blame and falls back to `form`.
        $this->postJson('/api/hrms/departments', [
            'name' => 'Engineering',
            'head_employee_id' => $employee->json('employee.id'),
        ])->assertStatus(422)->assertJsonValidationErrors('head_employee_id');
    }

    public function test_a_department_name_is_required_on_create_only(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $this->postJson('/api/hrms/departments', [])->assertStatus(422)->assertJsonValidationErrors('name');

        $id = $this->postJson('/api/hrms/departments', ['name' => 'Engineering'])
            ->assertCreated()->json('department.id');

        // A rename that forgets the name is a client bug, but the endpoint must
        // not require a field it does not need in order to save a description.
        $this->putJson("/api/hrms/departments/{$id}", ['description' => 'Builds the product'])
            ->assertOk()
            ->assertJsonPath('department.description', 'Builds the product');
    }

    public function test_a_department_in_use_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $department = $this->postJson('/api/hrms/departments', ['name' => 'Engineering'])
            ->assertCreated()->json('department.id');
        $employee = $this->postJson('/api/hrms/employees', [
            'name' => 'One Person',
            'department_id' => $department,
        ])->assertCreated()->json('employee.id');

        $this->deleteJson("/api/hrms/departments/{$department}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('form');

        $this->postJson("/api/hrms/departments/{$department}/deactivate")
            ->assertOk()
            ->assertJsonPath('department.is_active', false);

        // Still there, because a payslip already names it.
        $this->assertDatabaseHas('departments', ['id' => $department, 'is_active' => false]);
        $this->assertDatabaseHas('employees', ['id' => $employee]);
    }

    public function test_an_empty_department_can_be_deleted(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $id = $this->postJson('/api/hrms/departments', ['name' => 'Engineering'])
            ->assertCreated()->json('department.id');

        $this->deleteJson("/api/hrms/departments/{$id}")->assertOk();

        $this->assertDatabaseMissing('departments', ['id' => $id]);
    }

    // ------------------------------------------------------------ reorder

    public function test_reorder_is_not_swallowed_by_the_resource_placeholder(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $a = $this->postJson('/api/hrms/departments', ['name' => 'A'])->assertCreated()->json('department.id');
        $b = $this->postJson('/api/hrms/departments', ['name' => 'B'])->assertCreated()->json('department.id');

        // "reorder" must not bind as a department id — that is a 404 on a
        // perfectly valid drag-and-drop.
        $this->postJson('/api/hrms/departments/reorder', ['ids' => [$b, $a]])
            ->assertOk()
            ->assertJsonPath('message', 'Departments reordered.');

        $created = Department::whereIn('id', [$a, $b])->orderBy('position')->get();

        $this->assertSame(['B', 'A'], $created->pluck('name')->all());
        $this->assertSame([1, 2], $created->pluck('position')->all());

        // The seeded departments are roots in this same list and the payload
        // omitted every one of them. `OrgNaming::order()` promises they keep
        // their relative order at the end rather than being dropped — a
        // guarantee a two-row list could never actually test.
        $seeded = Department::query()
            ->whereNotIn('id', [$a, $b])
            ->orderBy('position')
            ->pluck('name')
            ->all();

        $this->assertSame(
            ['Engineering', 'Sales', 'Operations'],
            $seeded,
            'siblings the payload omitted keep their relative order at the end',
        );
    }

    public function test_a_reorder_cannot_move_a_department_out_of_another_parent(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $parent = $this->postJson('/api/hrms/departments', ['name' => 'Company'])
            ->assertCreated()->json('department.id');
        $other = $this->postJson('/api/hrms/departments', ['name' => 'Other'])
            ->assertCreated()->json('department.id');
        $stranger = $this->postJson('/api/hrms/departments', ['name' => 'Legal', 'parent_id' => $other])
            ->assertCreated()->json('department.id');

        $this->postJson("/api/hrms/departments/reorder?parent_id={$parent}", [
            'ids' => [$stranger, $other],
        ])->assertOk();

        $this->assertSame(
            $other,
            Department::findOrFail($stranger)->parent_id,
            'a reorder of one list must not reach into another',
        );
    }

    public function test_a_reorder_needs_at_least_one_id(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $this->postJson('/api/hrms/departments/reorder', ['ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    // ------------------------------------------------------------ designation + location

    public function test_a_designation_may_span_departments(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $this->postJson('/api/hrms/designations', ['name' => 'Manager', 'level' => 4])
            ->assertCreated()
            ->assertJsonPath('designation.department_id', null)
            ->assertJsonPath('designation.level', 4);
    }

    public function test_a_designation_level_is_bounded(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        // A "level 0" sorts wrongly forever, and nobody notices until a payslip
        // prints the band wrong.
        $this->postJson('/api/hrms/designations', ['name' => 'Odd', 'level' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('level');
    }

    public function test_a_designation_in_use_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $designation = $this->postJson('/api/hrms/designations', ['name' => 'Engineer'])
            ->assertCreated()->json('designation.id');
        $this->postJson('/api/hrms/employees', [
            'name' => 'One Person',
            'designation_id' => $designation,
        ])->assertCreated();

        $this->deleteJson("/api/hrms/designations/{$designation}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('form');

        $this->postJson("/api/hrms/designations/{$designation}/deactivate")
            ->assertOk()
            ->assertJsonPath('designation.is_active', false);
    }

    public function test_a_complete_geofence_round_trips(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        // Identified by the id the response hands back, and named something the
        // tenant is not seeded with: looking a row up by name picks whichever
        // match sorts first, and a seeded "Headquarters" (P3.5) has no fence.
        $id = $this->postJson('/api/hrms/locations', [
            'name' => 'Pune Depot',
            'geo_lat' => 19.076,
            'geo_lng' => 72.8777,
            'geo_radius_m' => 150,
            'is_geo_fenced' => true,
        ])->assertCreated()
            ->assertJsonPath('location.is_geo_fenced', true)
            ->json('location.id');

        // Read back as a number, and still fenced: the decimal round trip must
        // not lose the trailing precision a radius check depends on.
        $this->getJson("/api/hrms/locations/{$id}")
            ->assertOk()
            ->assertJsonPath('location.geo_lat', 19.076)
            ->assertJsonPath('location.geo_radius_m', 150)
            ->assertJsonPath('location.is_geo_fenced', true);
    }

    public function test_a_half_configured_geofence_is_refused(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $this->postJson('/api/hrms/locations', [
            'name' => 'Headquarters',
            'geo_radius_m' => 150,
            'is_geo_fenced' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('form');
    }

    public function test_an_out_of_range_latitude_is_refused(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $this->postJson('/api/hrms/locations', ['name' => 'Nowhere', 'geo_lat' => 120])
            ->assertStatus(422)
            ->assertJsonValidationErrors('geo_lat');
    }

    public function test_a_location_without_a_fence_keeps_its_coordinates(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $this->postJson('/api/hrms/locations', [
            'name' => 'Headquarters',
            'geo_lat' => 19.076,
            'geo_lng' => 72.8777,
        ])->assertCreated()
            ->assertJsonPath('location.is_geo_fenced', false)
            ->assertJsonPath('location.geo_lat', 19.076);
    }

    public function test_a_location_in_use_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $location = $this->postJson('/api/hrms/locations', ['name' => 'Headquarters'])
            ->assertCreated()->json('location.id');
        $this->postJson('/api/hrms/employees', [
            'name' => 'One Person',
            'location_id' => $location,
        ])->assertCreated();

        $this->deleteJson("/api/hrms/locations/{$location}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('form');

        $this->postJson("/api/hrms/locations/{$location}/deactivate")
            ->assertOk()
            ->assertJsonPath('location.is_active', false);
    }

    public function test_editing_a_record_does_not_clear_what_it_omitted(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.org.view', 'hrms.org.manage', 'hrms.employees.manage']));

        $id = $this->postJson('/api/hrms/locations', [
            'name' => 'Headquarters',
            'city' => 'Pune',
            'address_line1' => '1 Market Street',
        ])->assertCreated()->json('location.id');

        $this->putJson("/api/hrms/locations/{$id}", ['name' => 'HQ'])
            ->assertOk()
            ->assertJsonPath('location.city', 'Pune')
            ->assertJsonPath('location.address_line1', '1 Market Street');
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function flatten(array $node): array
    {
        return [$node, ...collect($node['departments'])
            ->flatMap(fn (array $child): array => $this->flatten($child))
            ->all()];
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Org User {$sequence}",
            'email' => "org.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Org Role {$sequence}",
            'slug' => "org-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
