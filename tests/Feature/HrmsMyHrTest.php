<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P17.1 — the employee home aggregate.
 *
 * One request carries profile, leave, attendance, queues, inbox, hardware,
 * files, pay, performance and cases — with a bounded query count (the
 * N+1 guard lives here, not in review), a warm cache on repeat reads, an
 * empty home for logins without records, and a 404 for platform super
 * admins.
 */
class HrmsMyHrTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_home_carries_every_section(): void
    {
        $user = $this->userWithRecord();
        $this->actAs($user);

        $body = $this->getJson('/api/my/hr')->assertOk()->json();

        $this->assertSame('Alice Example', $body['profile']['name']);
        $this->assertSame([], $body['sections']['leave']['balances']);
        $this->assertArrayHasKey('present', $body['sections']['attendance']['month']);
        $this->assertSame(0, $body['sections']['inbox_count']);
        $this->assertSame([], $body['sections']['assets']);
        $this->assertNull($body['sections']['payroll']['latest']);
        $this->assertNotEmpty($body['quick_actions']);
    }

    public function test_one_request_stays_bounded_and_repeats_warm(): void
    {
        $user = $this->userWithRecord();
        $this->actAs($user);

        DB::enableQueryLog();
        $this->getJson('/api/my/hr')->assertOk();
        $first = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->getJson('/api/my/hr')->assertOk();
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(60, $first, "First load took {$first} queries — section reads must eager-load.");
        $this->assertLessThanOrEqual(2, $second, "Repeat load took {$second} queries — the 60s cache must hold.");
    }

    public function test_a_login_without_a_record_reads_an_empty_home(): void
    {
        $this->connectTenant('acme');

        $user = User::create([
            'name' => 'No Record',
            'email' => 'no.record@flowsync.test',
            'password' => 'password',
        ]);
        $this->actAs($user);

        $body = $this->getJson('/api/my/hr')->assertOk()->json();

        $this->assertNull($body['profile']);
        $this->assertSame([], $body['sections']);
        $this->assertSame([], $body['quick_actions']);
    }

    public function test_a_platform_super_admin_404s(): void
    {
        $admin = $this->systemUser('superadmin@flowsync.test');
        $this->actingAs($admin);

        $this->getJson('/api/my/hr')->assertNotFound();
    }

    // ------------------------------------------------------------ helpers

    private function userWithRecord(): User
    {
        $this->connectTenant('acme');

        $user = User::create([
            'name' => 'Alice Example',
            'email' => 'alice.example@flowsync.test',
            'password' => 'password',
        ]);

        Employee::create([
            'employee_code' => 'EMP-HOME-1',
            'name' => 'Alice Example',
            'status' => EmployeeStatus::Active,
            'user_id' => $user->id,
        ]);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }
}
