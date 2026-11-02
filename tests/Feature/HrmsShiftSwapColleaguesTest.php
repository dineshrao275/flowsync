<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * BUG-T6 — an Employee picks who to swap with from a names-only list.
 *
 * The employee directory needs `hrms.employees.view`, so a plain Employee's picker was empty. This
 * endpoint answers with `id` and `name` only: no code, email, salary or photo — just enough to choose.
 */
class HrmsShiftSwapColleaguesTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private User $ann;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.shifts']);

        $this->ann = $this->userWith(['hrms.view']);
        $this->makeEmployee('Ann Self', ['user_id' => $this->ann->id]);
    }

    public function test_a_plain_employee_gets_names_and_ids_only(): void
    {
        $bob = $this->makeEmployee('Bob Colleague', ['user_id' => $this->userWith(['hrms.view'])->id]);

        $this->actAs($this->ann);
        $rows = $this->getJson('/api/hrms/shift-swaps/colleagues')->assertOk()->json('colleagues');

        $this->assertSame([['id' => $bob->id, 'name' => 'Bob Colleague']], $rows);
    }

    public function test_the_list_leaves_out_the_caller_people_without_a_login_and_leavers(): void
    {
        $this->makeEmployee('No Login');
        $this->makeEmployee('Gone Leaver', ['user_id' => $this->userWith(['hrms.view'])->id, 'status' => EmployeeStatus::Terminated]);
        $kept = $this->makeEmployee('Kept Colleague', ['user_id' => $this->userWith(['hrms.view'])->id]);

        $this->actAs($this->ann);
        $names = collect($this->getJson('/api/hrms/shift-swaps/colleagues')->assertOk()->json('colleagues'))->pluck('name')->all();

        $this->assertSame([$kept->name], $names);
    }

    public function test_search_narrows_by_name_and_treats_wildcards_literally(): void
    {
        $this->makeEmployee('Carol Match', ['user_id' => $this->userWith(['hrms.view'])->id]);
        $this->makeEmployee('Dave Other', ['user_id' => $this->userWith(['hrms.view'])->id]);

        $this->actAs($this->ann);

        $this->assertSame(['Carol Match'], collect($this->getJson('/api/hrms/shift-swaps/colleagues?q=CAROL')->json('colleagues'))->pluck('name')->all());
        $this->assertSame([], $this->getJson('/api/hrms/shift-swaps/colleagues?q=%25')->json('colleagues'));
    }

    public function test_someone_without_an_employment_record_is_refused(): void
    {
        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson('/api/hrms/shift-swaps/colleagues')->assertForbidden();
    }

    public function test_the_shifts_module_gates_it(): void
    {
        $this->setAcmeModules(['hrms.core']);
        $this->actAs($this->ann);

        $this->getJson('/api/hrms/shift-swaps/colleagues')->assertForbidden();
    }
}
