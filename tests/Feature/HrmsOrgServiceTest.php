<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Org\Designation;
use App\Models\Hrms\Org\Location;
use App\Services\Hrms\Org\DepartmentChart;
use App\Services\Hrms\Org\DepartmentService;
use App\Services\Hrms\Org\DepartmentTree;
use App\Services\Hrms\Org\DesignationService;
use App\Services\Hrms\Org\LocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P3.2 — the org models, the tree and the three write services.
 *
 * The bulk of it is about the tree, because that is where the invariants are:
 * a cycle is a corruption that is invisible when it is written and fatal when
 * something walks it later, and a headcount that means the wrong thing is a
 * number a manager trusts.
 */
class HrmsOrgServiceTest extends TestCase
{
    use IsolatesDatabase;

    private function departments(): DepartmentService
    {
        return app(DepartmentService::class);
    }

    private function tree(): DepartmentTree
    {
        return app(DepartmentTree::class);
    }

    private function chart(): DepartmentChart
    {
        return app(DepartmentChart::class);
    }

    private function designations(): DesignationService
    {
        return app(DesignationService::class);
    }

    private function locations(): LocationService
    {
        return app(LocationService::class);
    }

    public function test_a_department_gets_a_unique_slug(): void
    {
        $first = $this->departments()->create(['name' => 'Engineering']);
        $second = $this->departments()->create(['name' => 'Engineering']);

        $this->assertSame('engineering', $first->slug);
        $this->assertSame('engineering-2', $second->slug);
    }

    public function test_renaming_a_department_does_not_collide_with_its_own_slug(): void
    {
        $department = $this->departments()->create(['name' => 'Engineering']);

        // Same name, so the slug resolves to itself. Treating that as a
        // collision would 422 a no-op edit and push the UI into inventing a
        // "-2" the user never asked for.
        $updated = $this->departments()->update($department, ['name' => 'Engineering']);

        $this->assertSame('engineering', $updated->slug);
    }

    public function test_a_department_cannot_be_its_own_parent(): void
    {
        $department = $this->departments()->create(['name' => 'Engineering']);

        $this->expectException(ValidationException::class);

        $this->tree()->reparent($department, $department);
    }

    public function test_a_department_cannot_move_under_its_own_child(): void
    {
        $parent = $this->departments()->create(['name' => 'Engineering']);
        $child = $this->departments()->create(['name' => 'Platform', 'parent_id' => $parent->id]);

        $this->expectException(ValidationException::class);

        // Platform would become its own grandparent.
        $this->tree()->reparent($parent, $child);
    }

    public function test_a_department_can_move_under_a_sibling(): void
    {
        $root = $this->departments()->create(['name' => 'Company']);
        $engineering = $this->departments()->create(['name' => 'Engineering', 'parent_id' => $root->id]);
        $sales = $this->departments()->create(['name' => 'Sales', 'parent_id' => $root->id]);

        $moved = $this->tree()->reparent($sales, $engineering);

        $this->assertSame($engineering->id, $moved->parent_id);
    }

    public function test_moving_a_department_carries_its_subtree(): void
    {
        $root = $this->departments()->create(['name' => 'Company']);
        $engineering = $this->departments()->create(['name' => 'Engineering']);
        $sales = $this->departments()->create(['name' => 'Sales']);
        $this->departments()->create(['name' => 'Platform', 'parent_id' => $engineering->id]);

        $this->tree()->reparent($engineering, $sales);

        $below = array_map(
            fn (Department $department): string => $department->name,
            $this->tree()->descendantsOf($engineering),
        );

        $this->assertSame(['Platform'], $below);
    }

    public function test_a_cycle_in_imported_data_terminates(): void
    {
        // The write path refuses to build this, but an org table imported from
        // a spreadsheet can arrive already tangled, and the reader has to
        // survive it. A loop here is a hung request on the org page.
        $a = $this->insertDepartment(['name' => 'A']);
        $b = $this->insertDepartment(['name' => 'B', 'parent_id' => $a->id]);
        DB::table('departments')->where('id', $a)->update(['parent_id' => $b->id]);

        $descendants = $this->tree()->descendantsOf($a->refresh());

        $this->assertSame([$b->id], array_map(fn (Department $d): int => $d->id, $descendants));
    }

    public function test_a_cycle_in_imported_data_still_builds_a_tree(): void
    {
        $a = $this->insertDepartment(['name' => 'A']);
        $b = $this->insertDepartment(['name' => 'B', 'parent_id' => $a->id]);
        DB::table('departments')->where('id', $a)->update(['parent_id' => $b->id]);

        $tree = $this->chart()->build();

        $this->assertNotEmpty($tree, 'a tangled tree must render, not vanish');
        $this->assertSame(
            ['A', 'B'],
            collect($tree)->flatMap(fn (array $node): array => $this->flatten($node))->pluck('name')->all(),
        );
    }

    public function test_the_path_to_a_department_reads_from_the_root_down(): void
    {
        $root = $this->insertDepartment(['name' => 'Company']);
        $engineering = $this->insertDepartment(['name' => 'Engineering', 'parent_id' => $root->id]);
        $platform = $this->insertDepartment(['name' => 'Platform', 'parent_id' => $engineering->id]);

        $this->assertSame(
            ['Company', 'Engineering', 'Platform'],
            array_map(fn (Department $d): string => $d->name, $this->tree()->pathTo($platform->refresh())),
        );
    }

    public function test_the_tree_reports_depth_and_both_headcounts(): void
    {
        $root = $this->insertDepartment(['name' => 'Company']);
        $engineering = $this->insertDepartment(['name' => 'Engineering', 'parent_id' => $root->id]);
        $platform = $this->insertDepartment(['name' => 'Platform', 'parent_id' => $engineering->id]);

        $this->insertEmployee(['department_id' => $engineering->id]);
        $this->insertEmployee(['department_id' => $engineering->id]);
        $this->insertEmployee(['department_id' => $platform->id]);

        $tree = $this->chart()->build();
        $this->assertCount(1, $tree);

        $company = $tree[0];
        $this->assertSame('Company', $company['name']);
        $this->assertSame(0, $company['depth']);
        $this->assertSame(3, $company['headcount'], 'the subtree total');
        $this->assertSame(0, $company['direct_count']);

        $engineering = $company['departments'][0];
        $this->assertSame(1, $engineering['depth']);
        $this->assertSame(3, $engineering['headcount']);
        $this->assertSame(2, $engineering['direct_count']);

        $platform = $engineering['departments'][0];
        $this->assertSame(2, $platform['depth']);
        $this->assertSame(1, $platform['headcount']);
        $this->assertSame(1, $platform['direct_count']);
    }

    public function test_a_deleted_employee_stops_counting_towards_the_headcount(): void
    {
        $department = $this->insertDepartment(['name' => 'Engineering']);
        $leaver = $this->insertEmployee(['department_id' => $department->id, 'status' => EmployeeStatus::Exited]);

        $this->assertSame(1, $this->chart()->build()[0]['direct_count']);

        DB::table('employees')->where('id', $leaver)->update(['deleted_at' => now()]);

        $this->assertSame(0, $this->chart()->build()[0]['direct_count']);
    }

    public function test_a_department_whose_parent_vanished_is_still_shown(): void
    {
        // The milder cousin of a cycle: the row exists, its parent does not.
        // Dropping it would hide a real department from the org page with
        // nothing in the logs.
        $this->insertDepartment(['name' => 'Company']);

        // The foreign key normally makes this impossible, which is the point —
        // but an org table imported from a spreadsheet can arrive tangled, and
        // the reader has to survive it.
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->insertDepartment(['name' => 'Orphan', 'parent_id' => 9999]);
        DB::statement('PRAGMA foreign_keys = ON');

        $names = collect($this->chart()->build())
            ->flatMap(fn (array $node): array => $this->flatten($node))
            ->pluck('name')
            ->all();

        $this->assertSame(['Company', 'Orphan'], $names);
    }

    public function test_a_department_head_has_to_be_an_active_employee(): void
    {
        $leaver = $this->insertEmployee(['status' => EmployeeStatus::Exited]);

        $this->expectException(ValidationException::class);

        // Naming somebody who has left as the head of a team shows the org
        // chart a manager who no longer works here, and it looks correct.
        $this->departments()->create(['name' => 'Engineering', 'head_employee_id' => $leaver]);
    }

    public function test_a_department_head_can_be_set_and_cleared(): void
    {
        $head = $this->insertEmployee();
        $department = $this->departments()->create(['name' => 'Engineering', 'head_employee_id' => $head]);

        $this->assertSame($head, $department->head_employee_id);
        $this->assertSame($head, $department->head->id, 'the head relation has to resolve');

        $cleared = $this->departments()->update($department, ['head_employee_id' => null]);

        $this->assertNull($cleared->head_employee_id);
    }

    public function test_renaming_a_department_does_not_clear_its_head(): void
    {
        $head = $this->insertEmployee();
        $department = $this->departments()->create(['name' => 'Engineering', 'head_employee_id' => $head]);

        // The head is set by its own picker, so a rename arrives without one.
        // Defaulting it to null here would strip the team's manager off the
        // chart on every unrelated edit.
        $renamed = $this->departments()->update($department, ['name' => 'Engineering & Platform']);

        $this->assertSame($head, $renamed->head_employee_id);
    }

    public function test_editing_a_department_does_not_clear_what_it_did_not_mention(): void
    {
        $department = $this->departments()->create(['name' => 'Engineering', 'code' => 'ENG', 'description' => 'Builds']);

        $updated = $this->departments()->update($department, ['description' => 'Builds the product']);

        $this->assertSame('Builds the product', $updated->description);
        $this->assertSame('ENG', $updated->code);
        $this->assertTrue($updated->is_active);
    }

    public function test_a_department_with_employees_cannot_be_deleted(): void
    {
        $department = $this->insertDepartment(['name' => 'Engineering']);
        $this->insertEmployee(['department_id' => $department->id]);

        $this->expectException(ValidationException::class);

        $this->departments()->delete($department->refresh());
    }

    public function test_a_department_with_children_cannot_be_deleted(): void
    {
        $parent = $this->insertDepartment(['name' => 'Engineering']);
        $this->insertDepartment(['name' => 'Platform', 'parent_id' => $parent->id]);

        $this->expectException(ValidationException::class);

        $this->departments()->delete($parent->refresh());
    }

    public function test_an_empty_department_can_be_deleted(): void
    {
        $department = $this->insertDepartment(['name' => 'Engineering']);

        $this->departments()->delete($department->refresh());

        $this->assertFalse(Department::where('id', $department->id)->exists());
    }

    public function test_a_department_can_be_deactivated_instead(): void
    {
        $department = $this->insertDepartment(['name' => 'Engineering']);
        $this->insertEmployee(['department_id' => $department->id]);

        // The row has to survive: a department in a payslip's department field
        // is history, and deleting it would rewrite what payroll reported.
        $deactivated = $this->departments()->deactivate($department->refresh());

        $this->assertFalse($deactivated->is_active);
        $this->assertTrue(Department::where('id', $department->id)->exists());
    }

    public function test_siblings_are_ordered_and_reorder_collapses_the_gaps(): void
    {
        $parent = $this->insertDepartment(['name' => 'Company']);
        $a = $this->insertDepartment(['name' => 'A', 'parent_id' => $parent->id, 'position' => 1]);
        $b = $this->insertDepartment(['name' => 'B', 'parent_id' => $parent->id, 'position' => 2]);
        $c = $this->insertDepartment(['name' => 'C', 'parent_id' => $parent->id, 'position' => 3]);

        $this->departments()->reorder($parent, [$c->id, $a->id, $b->id]);

        $ordered = Department::where('parent_id', $parent->id)->orderBy('position')->get();
        $this->assertSame(['C', 'A', 'B'], $ordered->pluck('name')->all());
        $this->assertSame([1, 2, 3], $ordered->pluck('position')->all());
    }

    public function test_a_partial_reorder_keeps_the_omitted_department(): void
    {
        $parent = $this->insertDepartment(['name' => 'Company']);
        $a = $this->insertDepartment(['name' => 'A', 'parent_id' => $parent->id, 'position' => 1]);
        $b = $this->insertDepartment(['name' => 'B', 'parent_id' => $parent->id, 'position' => 2]);
        $c = $this->insertDepartment(['name' => 'C', 'parent_id' => $parent->id, 'position' => 3]);

        // Trusting a submitted id list blindly is how a department silently
        // falls off the end of its parent's list.
        $this->departments()->reorder($parent, [$c->id, $a->id]);

        $this->assertSame(
            ['C', 'A', 'B'],
            Department::where('parent_id', $parent->id)->orderBy('position')->pluck('name')->all(),
        );
    }

    public function test_a_reorder_cannot_reach_into_another_parents_list(): void
    {
        $engineering = $this->insertDepartment(['name' => 'Engineering', 'position' => 1]);
        $sales = $this->insertDepartment(['name' => 'Sales', 'position' => 2]);

        $other = $this->insertDepartment(['name' => 'Other']);
        $stranger = $this->insertDepartment(['name' => 'Legal', 'parent_id' => $other->id, 'position' => 7]);

        // Naming an id that is not a sibling of this parent is not this list's
        // business. Honouring it would let a payload drag a department out of a
        // different parent — and reassign it a position, silently.
        $this->departments()->reorder(null, [$stranger->id, $sales->id, $engineering->id]);

        $this->assertSame(7, $stranger->refresh()->position);
        $this->assertSame($other->id, $stranger->parent_id);
        $this->assertSame(
            // 'Other' is a root too, and the payload omitted it, so it keeps
            // its relative order at the end rather than being dropped.
            ['Sales', 'Engineering', 'Other'],
            Department::where('parent_id', null)->orderBy('position')->pluck('name')->all(),
        );
    }

    public function test_a_rejected_re_parent_does_not_apply_the_rename(): void
    {
        $parent = $this->insertDepartment(['name' => 'Engineering']);
        $child = $this->insertDepartment(['name' => 'Platform', 'parent_id' => $parent->id]);

        try {
            $this->departments()->update($parent->refresh(), [
                'name' => 'Engineering & Platform',
                'parent_id' => $child->id,
            ]);
            $this->fail('a cyclic re-parent should have been refused');
        } catch (ValidationException) {
            // Expected.
        }

        // Scalars are applied before the re-parent is validated, so without a
        // transaction this request would commit the rename and then report 422
        // — a partial write on a rejected request.
        $this->assertSame('Engineering', $parent->refresh()->name);
    }

    public function test_a_new_department_lands_at_the_end_of_its_parents_list(): void
    {
        $parent = $this->insertDepartment(['name' => 'Company']);
        $this->insertDepartment(['name' => 'A', 'parent_id' => $parent->id, 'position' => 1]);

        $created = $this->departments()->create(['name' => 'B', 'parent_id' => $parent->id]);

        $this->assertSame(2, $created->position);
    }

    public function test_a_designation_spans_tenants_and_may_have_no_department(): void
    {
        // "Manager" is a level, not a department, so most bands span them.
        $designation = $this->designations()->create(['name' => 'Manager', 'level' => 4]);

        $this->assertNull($designation->department_id);
        $this->assertSame(4, $designation->level);
    }

    public function test_editing_a_designation_does_not_clear_its_level_or_department(): void
    {
        $department = $this->insertDepartment(['name' => 'Engineering']);
        $designation = $this->designations()->create([
            'name' => 'Senior Engineer',
            'level' => 3,
            'department_id' => $department->id,
        ]);

        $updated = $this->designations()->update($designation, ['code' => 'SE']);

        $this->assertSame('SE', $updated->code);
        $this->assertSame(3, $updated->level);
        $this->assertSame($department->id, $updated->department_id);
        $this->assertTrue($updated->department->is($department));
    }

    public function test_a_designation_held_by_employees_cannot_be_deleted(): void
    {
        $designation = $this->insertDesignation(['name' => 'Senior Engineer']);
        $this->insertEmployee(['designation_id' => $designation->id]);

        $this->expectException(ValidationException::class);

        $this->designations()->delete($designation->refresh());
    }

    public function test_an_unused_designation_can_be_deleted(): void
    {
        $designation = $this->insertDesignation(['name' => 'Intern']);

        $this->designations()->delete($designation->refresh());

        $this->assertFalse(Designation::where('id', $designation->id)->exists());
    }

    public function test_designation_reorder_is_gap_free(): void
    {
        $a = $this->insertDesignation(['name' => 'A', 'position' => 1]);
        $b = $this->insertDesignation(['name' => 'B', 'position' => 2]);
        $c = $this->insertDesignation(['name' => 'C', 'position' => 3]);

        $this->designations()->reorder([$c->id, $b->id, $a->id]);

        $ordered = Designation::orderBy('position')->get();
        $this->assertSame(['C', 'B', 'A'], $ordered->pluck('name')->all());
        $this->assertSame([1, 2, 3], $ordered->pluck('position')->all());
    }

    public function test_a_complete_geofence_is_accepted(): void
    {
        $location = $this->locations()->create([
            'name' => 'Headquarters',
            'geo_lat' => '19.0760000',
            'geo_lng' => '72.8777000',
            'geo_radius_m' => 150,
            'is_geo_fenced' => true,
        ]);

        $this->assertTrue($location->isGeoFenced());
    }

    public function test_a_half_configured_geofence_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        // A radius with no centre is the dangerous state: it looks configured,
        // and comparing it against a null centre either denies every remote
        // punch or matches everything.
        $this->locations()->create([
            'name' => 'Headquarters',
            'geo_radius_m' => 150,
            'is_geo_fenced' => true,
        ]);
    }

    public function test_a_zero_radius_is_refused_as_a_fence(): void
    {
        $this->expectException(ValidationException::class);

        $this->locations()->create([
            'name' => 'Headquarters',
            'geo_lat' => '19.0760000',
            'geo_lng' => '72.8777000',
            'geo_radius_m' => 0,
            'is_geo_fenced' => true,
        ]);
    }

    public function test_ticking_the_box_without_a_centre_does_not_produce_a_fence(): void
    {
        $location = $this->locations()->create(['name' => 'Headquarters']);

        $this->assertFalse($location->is_geo_fenced);
        $this->assertFalse($location->isGeoFenced());
    }

    public function test_coordinates_are_kept_when_the_fence_is_off(): void
    {
        // A site is useful on a map long before anybody configures a geofence.
        $location = $this->locations()->create([
            'name' => 'Headquarters',
            'geo_lat' => '19.0760000',
            'geo_lng' => '72.8777000',
        ]);

        $this->assertFalse($location->isGeoFenced());
        $this->assertSame('19.0760000', $location->geo_lat);
    }

    public function test_a_location_with_employees_cannot_be_deleted(): void
    {
        $location = $this->insertLocation(['name' => 'Headquarters']);
        $this->insertEmployee(['location_id' => $location->id]);

        $this->expectException(ValidationException::class);

        $this->locations()->delete($location->refresh());
    }

    public function test_editing_a_location_does_not_clear_its_address(): void
    {
        $location = $this->locations()->create([
            'name' => 'Headquarters',
            'address_line1' => '1 Market Street',
            'city' => 'Pune',
        ]);

        $updated = $this->locations()->update($location, ['name' => 'HQ']);

        $this->assertSame('1 Market Street', $updated->address_line1);
        $this->assertSame('Pune', $updated->city);
    }

    /**
     * Monotonic, because `uniqid()` is time-based and two inserts inside one
     * microsecond collide on the unique `employee_code`.
     */
    private static function token(): string
    {
        static $counter = 0;

        return (string) ++$counter;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function flatten(array $node): array
    {
        return [$node, ...collect($node['departments'])->flatMap(fn (array $child): array => $this->flatten($child))->all()];
    }

    private function insertDepartment(array $overrides = []): Department
    {
        $department = Department::create([
            'name' => 'Department '.self::token(),
            'slug' => 'department-'.self::token(),
            'is_active' => true,
            'position' => 0,
            ...$overrides,
        ]);

        return $department->refresh();
    }

    private function insertDesignation(array $overrides = []): Designation
    {
        return Designation::create([
            'name' => 'Designation '.self::token(),
            'slug' => 'designation-'.self::token(),
            'is_active' => true,
            'position' => 0,
            ...$overrides,
        ]);
    }

    private function insertLocation(array $overrides = []): Location
    {
        return Location::create([
            'name' => 'Location '.self::token(),
            'slug' => 'location-'.self::token(),
            'is_active' => true,
            'is_geo_fenced' => false,
            'position' => 0,
            ...$overrides,
        ]);
    }

    private function insertEmployee(array $overrides = []): int
    {
        return DB::table('employees')->insertGetId([
            'user_id' => null,
            'employee_code' => 'EMP-ORG'.self::token(),
            'name' => $overrides['name'] ?? 'Employee '.self::token(),
            'status' => $overrides['status'] ?? EmployeeStatus::Active->value,
            'work_mode' => 'office',
            ...$overrides,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
