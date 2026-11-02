<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Found through the UI: soft-deleting an employee who had an exit (or onboarding) case made
 * `GET /hrms/offboarding/cases` answer 500 for everyone, because the case's `employee` relation
 * skipped the trashed row and the presenter received null. A case is history; it keeps its person.
 */
class HrmsLifecycleDeletedEmployeeTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_offboarding_list_still_loads_after_the_employee_is_deleted(): void
    {
        $this->login('admin@flowsync.test');

        $employeeId = $this->postJson('/api/hrms/employees', ['name' => 'Gone Soon'])->assertCreated()->json('employee.id');
        $this->postJson('/api/hrms/offboarding/cases', [
            'employee_id' => $employeeId,
            'last_working_day' => now()->addMonth()->toDateString(),
            'reason' => 'resigned',
        ])->assertCreated();

        $this->deleteJson("/api/hrms/employees/{$employeeId}?force=1")->assertOk();

        $this->getJson('/api/hrms/offboarding/cases')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Gone Soon']);
    }

    public function test_the_onboarding_list_still_loads_after_the_employee_is_deleted(): void
    {
        $this->login('admin@flowsync.test');

        $employeeId = $this->postJson('/api/hrms/employees', ['name' => 'Gone Too'])->assertCreated()->json('employee.id');
        $templateId = $this->postJson('/api/hrms/onboarding/templates', [
            'name' => 'Std',
            'tasks' => [['title' => 'Say hello']],
        ])->assertCreated()->json('template.id');
        $this->postJson('/api/hrms/onboarding/cases', ['employee_id' => $employeeId, 'template_id' => $templateId])->assertCreated();

        $this->deleteJson("/api/hrms/employees/{$employeeId}?force=1")->assertOk();

        $this->getJson('/api/hrms/onboarding/cases')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Gone Too']);
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }
}
