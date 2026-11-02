<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Support\Hrms\EmployeeCodeGenerator;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * The unique index on `employee_code` covers soft-deleted rows, so the allocator has to count
 * them too. Found through the UI: after an employee was deleted, every new employee failed with
 * "Could not allocate a unique employee code" until the deleted row was purged.
 */
class HrmsEmployeeCodeSoftDeleteTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_soft_deleted_employees_code_is_never_reissued(): void
    {
        $generator = app(EmployeeCodeGenerator::class);

        Employee::create(['employee_code' => 'EMP-1', 'name' => 'Stays']);
        $gone = Employee::create(['employee_code' => 'EMP-2', 'name' => 'Leaves']);
        $gone->delete();

        $this->assertSame('EMP-3', $generator->next());
    }

    public function test_creating_after_deleting_the_newest_employee_succeeds(): void
    {
        $generator = app(EmployeeCodeGenerator::class);

        Employee::create(['employee_code' => 'EMP-1', 'name' => 'First'])->delete();

        $created = $generator->retrying(fn (string $code) => Employee::create([
            'employee_code' => $code,
            'name' => 'After delete',
        ]));

        $this->assertSame('EMP-2', $created->employee_code);
    }
}
