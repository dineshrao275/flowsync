<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\Hrms\Employee\EmployeeService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P2.3 — the employee HTTP surface.
 *
 * The service rules are covered in `HrmsEmployeeServiceTest`. What is worth
 * protecting *here* is the part the service cannot see: that the module gate and
 * `hrms.view` decide whether the surface exists, that the policy decides per
 * record (including the self-service case no tenant permission covers), that
 * `restricted` really withholds the personal fields rather than blanking them,
 * that the endpoints which own the identity of a record refuse to have that
 * identity set through the profile endpoint, and that the photo link is signed
 * and tenant-scoped.
 */
class HrmsEmployeeApiTest extends TestCase
{
    use IsolatesDatabase;

    // ------------------------------------------------------------- access

    public function test_a_tenant_without_the_hrms_module_has_no_employee_surface(): void
    {
        $this->setAcmeModules([]);
        $this->login('admin@flowsync.test');

        $this->getJson('/api/hrms/employees')->assertForbidden();
    }

    public function test_a_user_without_the_hrms_permission_is_refused(): void
    {
        $this->actAs($this->userWith([]));

        $this->getJson('/api/hrms/employees')->assertForbidden();
        $this->postJson('/api/hrms/employees', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_a_directory_reader_may_list_and_read_but_not_write(): void
    {
        $employee = $this->makeEmployee('Readable Person');
        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view']));

        $this->getJson('/api/hrms/employees')
            ->assertOk()
            ->assertJsonPath('employees.0.name', 'Readable Person');

        $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk();

        $this->postJson('/api/hrms/employees', ['name' => 'Denied'])->assertForbidden();
        $this->putJson("/api/hrms/employees/{$employee->id}", ['name' => 'Denied'])->assertForbidden();
        $this->postJson("/api/hrms/employees/{$employee->id}/terminate", ['reason' => 'no'])
            ->assertForbidden();
    }

    public function test_an_employee_may_read_their_own_record_without_the_directory_permission(): void
    {
        // The self-service case: no tenant role grants this, and it is the basis
        // of every "my payslip" surface later.
        $user = $this->userWith(['hrms.view']);
        $mine = $this->makeEmployee('My Own Record', ['user_id' => $user->id]);
        $theirs = $this->makeEmployee('Someone Else');

        $this->actAs($user);

        $this->getJson("/api/hrms/employees/{$mine->id}")->assertOk();
        $this->getJson("/api/hrms/employees/{$theirs->id}")->assertForbidden();

        // Reading is not writing: their own record is still not editable.
        $this->putJson("/api/hrms/employees/{$mine->id}", ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_the_personal_fields_are_masked_without_the_sensitive_permission(): void
    {
        $employee = $this->makeEmployee('Has Personal Data', [
            'personal_email' => 'private@flowsync.test',
            'phone' => '+15550001111',
            'date_of_birth' => '1990-04-01',
            'gender' => 'female',
            'marital_status' => 'married',
            'address_line1' => '1 Private Street',
            'city' => 'Bengaluru',
            'emergency_contact_name' => 'Ravi Kumar',
            'emergency_contact_phone' => '+15550002222',
            'emergency_contact_relation' => 'spouse',
            'notes' => 'Something the directory has no business showing.',
        ]);

        $reader = $this->userWith(['hrms.view', 'hrms.employees.view']);

        $this->actAs($reader);
        $body = $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk()->json('employee');

        // Every key present, same shape as the privileged payload. A key that
        // vanishes forces every call site to ask whether the person has no phone
        // or the caller simply may not see it.
        $this->assertArrayHasKey('personal_email', $body);
        $this->assertArrayHasKey('phone', $body);
        $this->assertArrayHasKey('date_of_birth', $body);
        $this->assertArrayHasKey('address', $body);
        $this->assertArrayHasKey('emergency_contact', $body);
        $this->assertTrue($body['restricted'], 'A restricted record should say so, not look empty.');

        // Masked, and not reconstructible from what is left.
        $this->assertSame('p***@***', $body['personal_email']);
        $this->assertStringNotContainsString('flowsync', $body['personal_email']);
        $this->assertSame('*********11', $body['phone'], 'Nine of eleven digits masked, the last two kept.');
        $this->assertSame('R***', $body['emergency_contact']['name']);
        $this->assertSame('*********22', $body['emergency_contact']['phone']);

        // Dropped whole, because there is no partial mask of them worth having.
        $this->assertNull($body['date_of_birth'], 'A year of birth still identifies someone in a small company.');
        $this->assertNull($body['gender']);
        $this->assertNull($body['marital_status']);
        $this->assertNull($body['address']['line1']);
        $this->assertNull($body['address']['city']);
        $this->assertNull($body['emergency_contact']['relation'], '"spouse" is a disclosure about the employee.');
        $this->assertNull($body['notes']);

        // And a photo is a face, so it is gated the same way.
        $this->assertNull($body['photo_url']);

        $privileged = $this->userWith([
            'hrms.view',
            'hrms.employees.view',
            'hrms.documents.view_sensitive',
        ]);

        $this->actAs($privileged);
        $body = $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk()->json('employee');

        $this->assertSame('private@flowsync.test', $body['personal_email']);
        $this->assertSame('+15550001111', $body['phone']);
        $this->assertSame('1990-04-01', $body['date_of_birth']);
        $this->assertSame('1 Private Street', $body['address']['line1']);
        $this->assertSame('Ravi Kumar', $body['emergency_contact']['name']);
        $this->assertFalse($body['restricted']);
    }

    public function test_an_unknown_work_mode_filter_is_a_422_not_a_500(): void
    {
        $this->login('admin@flowsync.test');

        // The filter is cast with WorkMode::from(). Validated as a plain string,
        // an unknown value reaches that cast and the request 500s — a bad query
        // parameter must never be able to take an endpoint down.
        $this->getJson('/api/hrms/employees?work_mode=teletubby')
            ->assertStatus(422)
            ->assertJsonValidationErrors('work_mode');
    }

    public function test_a_status_colour_is_a_hex_the_clients_can_actually_style(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Painted');

        $color = $this->getJson("/api/hrms/employees/{$employee->id}")
            ->assertOk()
            ->json('employee.status_color');

        // The directory and the profile both render `backgroundColor: ${color}22`.
        // A palette name like "emerald" makes that `emerald22`, which is not a
        // colour, and the pill loses its background silently.
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $color);
    }

    public function test_the_status_history_carries_both_ends_of_every_transition(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Mover');

        $this->postJson("/api/hrms/employees/{$employee->id}/status", [
            'to' => 'on_notice',
            'reason' => 'resignation accepted',
        ])->assertOk();

        $entry = $this->getJson("/api/hrms/employees/{$employee->id}")
            ->assertOk()
            ->json('status_history.0');

        // Both labels, not just the destination: a ledger that reads "On Notice"
        // alone has lost the transition, which is the part somebody reads when
        // they ask who moved this person and from where.
        $this->assertSame('Active', $entry['from_status_label']);
        $this->assertSame('On Notice', $entry['to_status_label']);
    }

    public function test_the_show_response_carries_the_manager_picker_options(): void
    {
        $employee = $this->makeEmployee('Has A Manager');
        $manager = $this->makeEmployee('Plain Manager');

        $this->login('admin@flowsync.test');
        $body = $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk()->json();

        $ids = array_column($body['filters']['managers'], 'id');

        // Everyone, not just the people who already have a report: a new hire
        // reporting to a first-time manager is an ordinary assignment, and with
        // a reports-only list the select is empty and the choice cannot be made.
        $this->assertContains($manager->id, $ids);
        $this->assertContains($employee->id, $ids);
    }

    public function test_the_directory_masks_the_personal_fields_even_for_a_privileged_reader(): void
    {
        $this->makeEmployee('Listed Person', [
            'personal_email' => 'private@flowsync.test',
            'phone' => '+15550001111',
        ]);

        // Deliberately the tenant admin, who may read any single record in full.
        $this->login('admin@flowsync.test');

        $row = $this->getJson('/api/hrms/employees')->assertOk()->json('employees.0');

        // A list of fifty people with their home addresses is a bulk-harvest
        // surface that no single-record screen is, and the directory does not
        // need a home address to do its job.
        $this->assertSame('p***@***', $row['personal_email']);
        $this->assertTrue($row['restricted']);
    }

    // ------------------------------------------------------ access logging

    public function test_reading_the_personal_fields_writes_a_data_access_row(): void
    {
        $employee = $this->makeEmployee('Watched', [
            'personal_email' => 'private@flowsync.test',
            'phone' => '+15550001111',
        ]);

        $privileged = $this->userWith([
            'hrms.view',
            'hrms.employees.view',
            'hrms.documents.view_sensitive',
        ]);

        $this->actAs($privileged);
        $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk();

        $log = HrmsDataAccessLog::where('record_id', $employee->id)->sole();

        $this->assertSame((new Employee)->getMorphClass(), $log->model);
        $this->assertSame('view', $log->action->value);
        $this->assertSame($privileged->id, $log->actor_user_id);
        $this->assertNotNull($log->ip_address);
        // Field *names* only — never values. And only what was actually set,
        // because a log listing all sixteen columns for a record with two filled
        // cannot tell one read from another.
        $this->assertSame(['personal_email', 'phone'], $log->fields);
    }

    public function test_a_masked_read_writes_no_data_access_row(): void
    {
        $employee = $this->makeEmployee('Unwatched', ['personal_email' => 'private@flowsync.test']);

        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view']));
        $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk();

        // Nothing was exposed, so claiming a read of sensitive data would be a
        // false record — and it would make a real one look like the anomaly.
        $this->assertSame(0, HrmsDataAccessLog::where('record_id', $employee->id)->count());
    }

    public function test_a_record_with_nothing_personal_on_it_writes_no_row(): void
    {
        $employee = $this->makeEmployee('Bare');

        $this->actAs($this->userWith([
            'hrms.view',
            'hrms.employees.view',
            'hrms.documents.view_sensitive',
        ]));
        $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk();

        $this->assertSame(0, HrmsDataAccessLog::where('record_id', $employee->id)->count());
    }

    public function test_a_photo_download_writes_a_data_access_row(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('employees/face.png', 'binary');

        $employee = $this->makeEmployee('Has A Face', [
            'photo_path' => 'employees/face.png',
            'personal_email' => 'private@flowsync.test',
        ]);

        $privileged = $this->userWith([
            'hrms.view',
            'hrms.employees.view',
            'hrms.documents.view_sensitive',
        ]);

        $this->actAs($privileged);
        $url = $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk()->json('employee.photo_url');

        $this->assertNotNull($url);
        $this->get($url)->assertOk();

        $log = HrmsDataAccessLog::where('action', 'download')->sole();

        $this->assertSame($employee->id, $log->record_id);
        // Attributable, which is the whole point: the download route has no
        // session, so the actor rides inside the signature instead.
        $this->assertSame($privileged->id, $log->actor_user_id);
        $this->assertSame(['photo_path'], $log->fields);
    }

    public function test_tampering_with_the_signed_actor_does_not_work(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('employees/face.png', 'binary');

        $employee = $this->makeEmployee('Has A Face', ['photo_path' => 'employees/face.png']);

        $this->actAs($this->userWith([
            'hrms.view',
            'hrms.employees.view',
            'hrms.documents.view_sensitive',
        ]));
        $url = $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk()->json('employee.photo_url');

        // Re-pointing the read at another account is exactly what the signature
        // exists to prevent — otherwise the download log could be forged.
        $this->get(str_replace('actor=', 'actor=9', $url))->assertForbidden();
        $this->assertSame(0, HrmsDataAccessLog::where('action', 'download')->count());
    }

    // ------------------------------------------------------------- index

    public function test_the_index_returns_the_uniform_shape(): void
    {
        $this->login('admin@flowsync.test');
        $this->makeEmployee('Indexed');

        $body = $this->getJson('/api/hrms/employees')->assertOk()->json();

        $this->assertSame(['current_page', 'last_page', 'per_page', 'total'], array_keys($body['pagination']));
        $this->assertSame(1, $body['pagination']['total']);
        $this->assertArrayHasKey('employment_types', $body['filters']);
        $this->assertArrayHasKey('managers', $body['filters']);
        $this->assertArrayHasKey('statuses', $body['filters']);
        // Served from the enum so the SPA cannot offer a work mode the API
        // rejects; a JS copy of a PHP enum is drift no test can catch.
        $this->assertSame(
            ['office', 'hybrid', 'remote'],
            array_column($body['filters']['work_modes'], 'value'),
        );
        $this->assertSame('admin', $body['my_role']);
        $this->assertSame('EMP-1', $body['employees'][0]['employee_code']);
        $this->assertSame('active', $body['employees'][0]['status']);
        $this->assertNull($body['employees'][0]['manager']);
    }

    public function test_the_index_filters_and_sorts(): void
    {
        $this->login('admin@flowsync.test');
        $this->makeEmployee('Zoe Alpha');
        $this->makeEmployee('Adam Beta');

        $this->getJson('/api/hrms/employees?q=Alpha')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/hrms/employees?status=active')->assertOk()->assertJsonPath('pagination.total', 2);
        $this->getJson('/api/hrms/employees?status=terminated')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/hrms/employees?work_mode=remote')->assertOk()->assertJsonPath('pagination.total', 0);

        $sorted = $this->getJson('/api/hrms/employees?sort=name&dir=desc')->assertOk()->json('employees');
        $this->assertSame('Zoe Alpha', $sorted[0]['name']);
    }

    public function test_the_index_rejects_a_bad_filter_and_an_unknown_sort_column(): void
    {
        $this->login('admin@flowsync.test');

        $this->getJson('/api/hrms/employees?status=retired')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/hrms/employees?per_page=9999')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/hrms/employees?joined_to=nonsense')->assertUnprocessable()->assertJsonValidationErrors('joined_to');

        // A column the whitelist does not hold is dropped, not an error: a
        // bookmarked URL from a future version should still render.
        $this->getJson('/api/hrms/employees?sort=password')->assertOk();
    }

    public function test_the_index_respects_the_page_size(): void
    {
        $this->login('admin@flowsync.test');

        for ($i = 0; $i < 3; $i++) {
            $this->makeEmployee("Paged {$i}");
        }

        $this->getJson('/api/hrms/employees?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'employees')
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonPath('pagination.last_page', 2);
    }

    // ------------------------------------------------------------- store

    public function test_an_employee_can_be_created(): void
    {
        $this->login('admin@flowsync.test');

        $this->postJson('/api/hrms/employees', [
            'name' => 'Hired Via API',
            'personal_email' => 'hired@flowsync.test',
            'designation' => 'Engineer',
            'work_mode' => 'remote',
        ])
            ->assertCreated()
            ->assertJsonPath('employee.name', 'Hired Via API')
            ->assertJsonPath('employee.employee_code', 'EMP-1')
            ->assertJsonPath('employee.work_mode', 'remote');

        $this->assertDatabaseHas('employees', ['name' => 'Hired Via API']);
    }

    public function test_an_employee_can_be_created_with_an_inline_login(): void
    {
        $this->login('admin@flowsync.test');

        $this->postJson('/api/hrms/employees', [
            'name' => 'Inline Hire',
            'email' => 'Inline.Hire@Acme.Test',
            'password' => 'password',
            'roles' => ['viewer'],
        ])->assertCreated()->assertJsonPath('employee.user.email', 'inline.hire@acme.test');

        $this->assertDatabaseHas('users', ['email' => 'inline.hire@acme.test']);
        $this->assertSame(1, TenantUserRouting::where('email', 'inline.hire@acme.test')->count());
    }

    public function test_the_created_login_can_actually_sign_in(): void
    {
        // The routing row is the whole point of the inline path, and the only
        // way to know it was written correctly is to log in with it.
        $this->login('admin@flowsync.test');
        $this->postJson('/api/hrms/employees', [
            'name' => 'Signs In',
            'email' => 'signs.in@acme.test',
            'password' => 'password',
        ])->assertCreated();

        auth()->logout();
        $this->flushSession();

        $this->postJson('/api/auth/login', ['email' => 'signs.in@acme.test', 'password' => 'password'])
            ->assertOk();
    }

    public function test_an_employee_can_be_linked_to_an_existing_login(): void
    {
        $this->login('admin@flowsync.test');
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();

        $this->postJson('/api/hrms/employees', ['name' => 'Linked', 'user_id' => $editor->id])
            ->assertCreated()
            ->assertJsonPath('employee.user.id', $editor->id);
    }

    public function test_store_validation_rejects_the_obvious_mistakes(): void
    {
        $this->login('admin@flowsync.test');

        $this->postJson('/api/hrms/employees', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/hrms/employees', ['name' => 'Bad', 'work_mode' => 'telepathic'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_mode');

        $this->postJson('/api/hrms/employees', ['name' => 'Bad', 'personal_email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('personal_email');

        $this->postJson('/api/hrms/employees', ['name' => 'Bad', 'email' => 'x@acme.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/hrms/employees', ['name' => 'Bad', 'roles' => ['wizard']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles.0');

        $this->postJson('/api/hrms/employees', [
            'name' => 'Bad',
            'probation_end_date' => '2020-01-01',
            'joining_date' => '2024-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('probation_end_date');

        $this->postJson('/api/hrms/employees', ['name' => 'Bad', 'employment_type_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employment_type_id');
    }

    public function test_store_refuses_to_link_and_create_a_login_at_once(): void
    {
        $this->login('admin@flowsync.test');
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();

        $this->postJson('/api/hrms/employees', [
            'name' => 'Ambiguous',
            'user_id' => $editor->id,
            'email' => 'ambiguous@acme.test',
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->assertDatabaseMissing('employees', ['name' => 'Ambiguous']);
    }

    public function test_store_refuses_a_duplicate_login_email(): void
    {
        $this->login('admin@flowsync.test');

        $this->postJson('/api/hrms/employees', [
            'name' => 'First',
            'email' => 'dupe@acme.test',
            'password' => 'password',
        ])->assertCreated();

        $this->postJson('/api/hrms/employees', [
            'name' => 'Second',
            'email' => 'DUPE@acme.test',
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_store_enforces_the_employee_quota(): void
    {
        $this->assignPlanWithEmployeeLimit(1);
        $this->login('admin@flowsync.test');

        $this->postJson('/api/hrms/employees', ['name' => 'One'])->assertCreated();
        $this->postJson('/api/hrms/employees', ['name' => 'Two'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form');
    }

    // ------------------------------------------------------------- update

    public function test_a_profile_can_be_updated(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Before');

        $this->putJson("/api/hrms/employees/{$employee->id}", [
            'name' => 'After',
            'designation' => 'Staff Engineer',
            'city' => 'Pune',
        ])
            ->assertOk()
            ->assertJsonPath('employee.name', 'After')
            ->assertJsonPath('employee.designation', 'Staff Engineer');

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'name' => 'After']);
    }

    public function test_update_refuses_the_fields_that_have_their_own_endpoint(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Locked Down');
        $manager = $this->makeEmployee('Some Manager');

        // Silently ignoring these would answer 200 to a client that believes it
        // changed a status or moved a reporting line.
        foreach (['status', 'manager_id', 'employee_code', 'email', 'password', 'roles', 'user_id'] as $field) {
            $payload = ['name' => 'Fine'];

            if ($field === 'status') {
                $payload['status'] = EmployeeStatus::Terminated->value;
            } elseif ($field === 'manager_id') {
                $payload['manager_id'] = $manager->id;
            } elseif ($field === 'email') {
                $payload['email'] = 'x@acme.test';
            } elseif ($field === 'password') {
                $payload['password'] = 'password';
            } elseif ($field === 'employee_code') {
                $payload['employee_code'] = 'EMP-99';
            } elseif ($field === 'user_id') {
                $payload['user_id'] = 1;
            } else {
                $payload['roles'] = ['viewer'];
            }

            $this->putJson("/api/hrms/employees/{$employee->id}", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'name' => 'Locked Down',
            'status' => 'active',
            'manager_id' => null,
        ]);
    }

    // ------------------------------------------------------------ destroy

    public function test_a_record_can_be_deleted_and_then_disappears_from_the_directory(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Removable');

        $this->deleteJson("/api/hrms/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Employee deleted.');

        $this->getJson("/api/hrms/employees/{$employee->id}")->assertNotFound();
        $this->getJson('/api/hrms/employees')->assertJsonPath('pagination.total', 0);

        // Soft: the row and its history are still there for payroll.
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_a_departed_employee_needs_an_explicit_force(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Already Exited');
        app(EmployeeService::class)->changeStatus($employee, EmployeeStatus::Exited);

        $this->deleteJson("/api/hrms/employees/{$employee->id}")->assertForbidden();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'deleted_at' => null]);

        $this->deleteJson("/api/hrms/employees/{$employee->id}?force=1")->assertOk();
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_a_reader_cannot_delete(): void
    {
        $employee = $this->makeEmployee('Safe From Readers');
        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view']));

        $this->deleteJson("/api/hrms/employees/{$employee->id}")->assertForbidden();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'deleted_at' => null]);
    }

    // -------------------------------------------------------- status flow

    public function test_a_status_change_is_recorded_and_returned(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Moving On');

        $this->postJson("/api/hrms/employees/{$employee->id}/status", [
            'to' => 'on_notice',
            'effective_date' => '2026-10-01',
            'reason' => 'resignation accepted',
        ])
            ->assertOk()
            ->assertJsonPath('employee.status', 'on_notice');

        $this->assertDatabaseHas('employee_status_history', [
            'employee_id' => $employee->id,
            'to_status' => 'on_notice',
            'reason' => 'resignation accepted',
        ]);

        // And it shows up in the profile drawer.
        $this->getJson("/api/hrms/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('status_history.0.to_status', 'on_notice')
            ->assertJsonPath('status_history.0.to_status_label', 'On Notice');
    }

    public function test_changing_to_the_current_status_is_a_422(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Already Active');

        $this->postJson("/api/hrms/employees/{$employee->id}/status", ['to' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->postJson("/api/hrms/employees/{$employee->id}/status", ['to' => 'nonsense'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_termination_requires_a_reason_and_stamps_the_record(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Leaving');

        $this->postJson("/api/hrms/employees/{$employee->id}/terminate", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/hrms/employees/{$employee->id}/terminate", [
            'reason' => 'resigned',
            'note' => 'handover done',
        ])
            ->assertOk()
            ->assertJsonPath('employee.status', 'terminated')
            ->assertJsonPath('employee.exited_reason', 'resigned');

        // A second termination is refused rather than a second exit date.
        $this->postJson("/api/hrms/employees/{$employee->id}/terminate", ['reason' => 'again'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    // ------------------------------------------------------ reporting line

    public function test_the_reporting_line_can_be_set_and_cleared(): void
    {
        $this->login('admin@flowsync.test');
        $boss = $this->makeEmployee('The Boss');
        $report = $this->makeEmployee('The Report');

        $this->postJson("/api/hrms/employees/{$report->id}/manager", ['manager_id' => $boss->id])
            ->assertOk()
            ->assertJsonPath('employee.manager.id', $boss->id);

        $this->postJson("/api/hrms/employees/{$report->id}/manager", ['manager_id' => null])
            ->assertOk()
            ->assertJsonPath('employee.manager', null);
    }

    public function test_a_reporting_loop_is_refused_at_the_endpoint(): void
    {
        $this->login('admin@flowsync.test');
        $ceo = $this->makeEmployee('Chief');
        $vp = $this->makeEmployee('Vice');

        $this->postJson("/api/hrms/employees/{$vp->id}/manager", ['manager_id' => $ceo->id])->assertOk();

        $this->postJson("/api/hrms/employees/{$ceo->id}/manager", ['manager_id' => $ceo->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_id');

        $this->postJson("/api/hrms/employees/{$ceo->id}/manager", ['manager_id' => $vp->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_id');
    }

    public function test_the_manager_field_is_required_on_its_own_endpoint(): void
    {
        // "present + nullable" and not merely "nullable": an absent manager_id
        // is a malformed call, while an explicit null is how a line is cleared.
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Needs A Manager');

        $this->postJson("/api/hrms/employees/{$employee->id}/manager", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_id');
    }

    // --------------------------------------------------------------- photo

    public function test_a_photo_is_served_through_a_signed_tenant_scoped_url(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('hrms/photos/1.png', 'binary');

        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Has A Photo', ['photo_path' => 'hrms/photos/1.png']);

        $url = $this->getJson("/api/hrms/employees/{$employee->id}")
            ->assertOk()
            ->json('employee.photo_url');

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('tenant=', $url);

        $this->get($url)->assertOk();
    }

    public function test_a_tampered_photo_url_is_refused(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('hrms/photos/2.png', 'binary');

        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Tamper Target', ['photo_path' => 'hrms/photos/2.png']);

        $url = $this->getJson("/api/hrms/employees/{$employee->id}")->json('employee.photo_url');

        // Pointing the signature at another tenant's file must not work.
        $this->get(str_replace('tenant=1', 'tenant=2', $url))->assertForbidden();
    }

    public function test_a_photo_url_works_from_a_fresh_session_on_the_central_connection(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('hrms/photos/3.png', 'binary');

        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Fresh Tab', ['photo_path' => 'hrms/photos/3.png']);

        $url = $this->getJson("/api/hrms/employees/{$employee->id}")->json('employee.photo_url');

        // The route sits outside switch_tenant, so the default connection here
        // is the central one — where the employees table does not exist. If the
        // tenant id were not inside the signature, this would 500.
        $this->flushSession();
        \DB::setDefaultConnection(config('tenancy.system.connection'));

        $this->get($url)->assertOk();
    }

    public function test_no_photo_means_a_null_url_and_a_404_on_the_route(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('No Photo');

        $this->getJson("/api/hrms/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('employee.photo_url', null);

        $this->get(URL::temporarySignedRoute('hrms.employees.photo', now()->addHour(), [
            'employee' => $employee->id,
            'tenant' => $this->acme()->id,
        ]))->assertNotFound();
    }

    // ------------------------------------------------------------ helpers

    /**
     * HTTP login, then reconnect the tenant.
     *
     * The login request resolves the tenant from the central routing index and
     * therefore runs on the central connection; anything the test then does
     * directly against a model would read `iso_system` and find no
     * `employees` table. HTTP-only tests do not notice this, which is why the
     * reconnect is the login helper's job here rather than every test's.
     */
    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }

    /**
     * Authenticate a purpose-built user, with the session pinned to Acme so
     * `switch_tenant` connects the same database the assertions read.
     */
    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * A user holding exactly the given permissions.
     *
     * A fresh role rather than an existing one, because detaching from `viewer`
     * or `editor` would leak into every other test in the file.
     *
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "HR User {$sequence}",
            'email' => "hr.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "HR Role {$sequence}",
            'slug' => "hr-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        $service = app(EmployeeService::class);

        $employee = $service->create(array_merge(['name' => $name], $overrides));

        // The service drops the fields the profile endpoints own, so anything
        // a test needs to set directly goes through the model — which is also a
        // standing check that those fields are not silently ignored.
        $direct = array_intersect_key($overrides, array_flip([
            'photo_path',
            'personal_email',
            'phone',
            'date_of_birth',
            'address_line1',
        ]));

        if ($direct !== []) {
            $employee->forceFill($direct)->save();
        }

        return $employee->fresh();
    }

    /**
     * P3.3 wired the three org references into the employee write path, so
     * they have to be assignable, readable back, and filterable — a column that
     * can be written but never read is a silent no-op from the client's side.
     */
    public function test_an_employee_can_be_placed_in_the_org_and_filtered_by_it(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view', 'hrms.employees.manage', 'hrms.org.view', 'hrms.org.manage']));

        $department = $this->postJson('/api/hrms/departments', ['name' => 'Engineering'])->assertCreated()->json('department.id');
        $designation = $this->postJson('/api/hrms/designations', ['name' => 'Engineer', 'level' => 3])->assertCreated()->json('designation.id');
        $location = $this->postJson('/api/hrms/locations', ['name' => 'Pune'])->assertCreated()->json('location.id');

        $placed = $this->postJson('/api/hrms/employees', [
            'name' => 'Placed Person',
            'department_id' => $department,
            'designation_id' => $designation,
            'location_id' => $location,
        ])->assertCreated();

        $placed->assertJsonPath('employee.department_id', $department)
            ->assertJsonPath('employee.designation_id', $designation)
            ->assertJsonPath('employee.location_id', $location);

        $this->postJson('/api/hrms/employees', ['name' => 'Unplaced Person'])->assertCreated();

        // The directory has to be able to answer the question, or the column is
        // write-only decoration.
        $filtered = $this->getJson("/api/hrms/employees?department_id={$department}")->assertOk();
        $filtered->assertJsonCount(1, 'employees')
            ->assertJsonPath('employees.0.name', 'Placed Person');

        // An exact match, never a subtree: the org context answers "and their
        // reports" separately and deliberately.
        $child = $this->postJson('/api/hrms/departments', ['name' => 'Platform', 'parent_id' => $department])->assertCreated()->json('department.id');
        $this->postJson('/api/hrms/employees', ['name' => 'Child Person', 'department_id' => $child])->assertCreated();

        $this->getJson("/api/hrms/employees?department_id={$department}")
            ->assertOk()
            ->assertJsonCount(1, 'employees')
            ->assertJsonPath('employees.0.name', 'Placed Person');
    }

    public function test_a_placement_must_point_at_a_real_org_record(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view', 'hrms.employees.manage']));

        $this->postJson('/api/hrms/employees', ['name' => 'Misplaced', 'department_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('department_id');
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }

    private function assignPlanWithEmployeeLimit(int $employees): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => ['employees' => $employees]]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
