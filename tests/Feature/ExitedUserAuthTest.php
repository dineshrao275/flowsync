<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * H-3: a tenant user whose employment record says `exited`/`terminated` must
 * not be able to start a session, even though their login credentials are
 * still valid. Suspended/on-notice staff are employed and keep access —
 * suspension is a work-state, not a revocation.
 */
class ExitedUserAuthTest extends TestCase
{
    use IsolatesDatabase;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function login(string $email, string $password = 'password')
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_an_exited_employee_cannot_sign_in(): void
    {
        Employee::factory()->for($this->user('editor@flowsync.test'))->create([
            'status' => EmployeeStatus::Exited,
        ]);

        $this->login('editor@flowsync.test')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_a_terminated_employee_cannot_sign_in(): void
    {
        Employee::factory()->for($this->user('editor@flowsync.test'))->create([
            'status' => EmployeeStatus::Terminated,
        ]);

        $this->login('editor@flowsync.test')->assertUnprocessable();
    }

    public function test_an_active_employee_can_sign_in(): void
    {
        Employee::factory()->for($this->user('editor@flowsync.test'))->create([
            'status' => EmployeeStatus::Active,
        ]);

        $this->login('editor@flowsync.test')->assertOk();
    }

    public function test_a_suspended_employee_can_still_sign_in(): void
    {
        Employee::factory()->for($this->user('editor@flowsync.test'))->create([
            'status' => EmployeeStatus::Suspended,
        ]);

        $this->login('editor@flowsync.test')->assertOk();
    }

    public function test_a_rehired_employee_can_sign_in_again(): void
    {
        Employee::factory()->for($this->user('editor@flowsync.test'))->create([
            'status' => EmployeeStatus::Active,
        ]);

        $this->login('editor@flowsync.test')->assertOk();
    }

    public function test_a_login_with_no_employee_record_is_unaffected(): void
    {
        $this->login('viewer@flowsync.test')->assertOk();
    }

    public function test_a_blocked_attempt_is_recorded_as_a_failed_sign_in(): void
    {
        Employee::factory()->for($this->user('editor@flowsync.test'))->create([
            'status' => EmployeeStatus::Exited,
        ]);

        $this->login('editor@flowsync.test')->assertUnprocessable();

        $log = AuditLog::where('action', 'auth.login_failed')->firstOrFail();

        $this->assertSame('employee_exited', $log->data['reason']);
        $this->assertSame('editor@flowsync.test', $log->data['email']);
    }
}
