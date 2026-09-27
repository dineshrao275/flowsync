<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P13.3 — the authenticated document surface.
 *
 * The service rules are covered in `HrmsDocumentServiceTest`. What is worth
 * protecting *here* is the part the service cannot see: that the policy
 * decides per record (including the self-service cases no tenant permission
 * grants), that a confidential row is invisible — not merely unreadable — to
 * a reader without the sensitive permission, that `expiring` is a warning
 * query rather than a document id, and that the upload validation lives in
 * the request.
 */
class HrmsDocumentApiTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_tenant_without_the_hrms_module_has_no_document_surface(): void
    {
        $this->setAcmeModules([]);
        $this->login('admin@flowsync.test');

        $this->getJson('/api/hrms/documents')->assertForbidden();
        $this->getJson('/api/hrms/documents/types')->assertForbidden();
    }

    public function test_a_user_without_the_hrms_permission_is_refused(): void
    {
        $this->actAs($this->userWith([]));

        $this->getJson('/api/hrms/documents')->assertForbidden();
        $this->getJson('/api/hrms/my/documents')->assertForbidden();
    }

    public function test_a_directory_reader_sees_everything_plain_but_nothing_confidential(): void
    {
        $mine = $this->makeEmployee('Own Record');
        $theirs = $this->makeEmployee('Other Record');
        $plain = $this->uploadFor($mine, ['title' => 'Plain contract']);
        $secret = $this->uploadFor($theirs, ['title' => 'Bank proof', 'confidential' => true]);
        $visa = $this->uploadFor($theirs, ['title' => 'Plain visa']);

        $this->actAs($this->userWith(['hrms.view', 'hrms.documents.view']));

        $ids = $this->getJson('/api/hrms/documents')->assertOk()->json('documents.*.id');

        $this->assertContains($plain->id, $ids);
        $this->assertContains($visa->id, $ids);
        $this->assertNotContains($secret->id, $ids, 'A list that names a confidential row leaks its existence.');

        $this->getJson("/api/hrms/documents/{$secret->id}")->assertForbidden();
        $this->getJson("/api/hrms/documents/{$plain->id}")->assertOk();
    }

    public function test_an_employee_may_read_and_file_their_own_documents(): void
    {
        $user = $this->userWith(['hrms.view']);
        $mine = $this->makeEmployee('My Own Record', ['user_id' => $user->id]);
        $theirs = $this->makeEmployee('Someone Else');
        $mineDoc = $this->uploadFor($mine, ['title' => 'My passport']);
        $theirsDoc = $this->uploadFor($theirs, ['title' => 'Their passport']);

        $this->actAs($user);

        // Their own row opens; someone else’s does not.
        $this->getJson("/api/hrms/documents/{$mineDoc->id}")->assertOk();
        $this->getJson("/api/hrms/documents/{$theirsDoc->id}")->assertForbidden();

        // The directory shows only their own file.
        $this->getJson('/api/hrms/documents')->assertOk()->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.title', 'My passport');

        // `my/documents` is the same question with a shorter URL.
        $this->getJson('/api/hrms/my/documents')->assertOk()->assertJsonCount(1, 'documents');

        // Filing into their own record works; filing into someone else’s is
        // a directory reader’s move, not theirs.
        $this->post('/api/hrms/documents', [
            'employee_id' => $mine->id,
            'document_type_id' => $this->makeType()->id,
            'title' => 'My new visa',
            'file' => UploadedFile::fake()->create('visa.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $this->post('/api/hrms/documents', [
            'employee_id' => $theirs->id,
            'document_type_id' => $this->makeType()->id,
            'title' => 'Not mine',
            'file' => UploadedFile::fake()->create('other.pdf', 100, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_verify_reject_and_delete_need_the_manage_permission(): void
    {
        $document = $this->uploadFor($this->makeEmployee('Managed Person'), ['title' => 'Contract']);

        $this->actAs($this->userWith(['hrms.view', 'hrms.documents.view']));

        $this->postJson("/api/hrms/documents/{$document->id}/verify")->assertForbidden();
        $this->postJson("/api/hrms/documents/{$document->id}/reject", ['reason' => 'no'])->assertForbidden();
        $this->deleteJson("/api/hrms/documents/{$document->id}")->assertForbidden();

        $this->login('admin@flowsync.test');

        $this->postJson("/api/hrms/documents/{$document->id}/verify")->assertOk()
            ->assertJsonPath('document.status', 'verified');

        $this->postJson("/api/hrms/documents/{$document->id}/reject", ['reason' => 'Unreadable scan.'])->assertOk()
            ->assertJsonPath('document.status', 'rejected');

        $this->postJson("/api/hrms/documents/{$document->id}/reject", [])->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->deleteJson("/api/hrms/documents/{$document->id}")->assertOk();
        $this->getJson("/api/hrms/documents/{$document->id}")->assertNotFound();
    }

    public function test_a_confidential_show_needs_the_sensitive_permission_and_logs_the_view(): void
    {
        $document = $this->uploadFor($this->makeEmployee('Private Person'), [
            'title' => 'Bank proof',
            'confidential' => true,
        ]);

        // A directory reader without the extra permission gets a 403, not a
        // redacted row: there is no partial view of a file worth having.
        $this->actAs($this->userWith(['hrms.view', 'hrms.documents.view']));
        $this->getJson("/api/hrms/documents/{$document->id}")->assertForbidden();

        $sensitive = $this->userWith(['hrms.view', 'hrms.documents.view', 'hrms.documents.view_sensitive']);
        $this->actAs($sensitive);
        $this->getJson("/api/hrms/documents/{$document->id}")->assertOk()
            ->assertJsonPath('document.title', 'Bank proof');

        $this->assertTrue(HrmsDataAccessLog::query()
            ->where('model', (new EmployeeDocument)->getMorphClass())
            ->where('record_id', $document->id)
            ->where('action', 'view')
            ->where('actor_user_id', $sensitive->id)
            ->exists(), 'Opening a confidential row is a sensitive read, file or metadata alike.');
    }

    public function test_expiring_is_a_warning_query_not_a_document_id(): void
    {
        $employee = $this->makeEmployee('Expiring Person');
        $this->uploadFor($employee, ['title' => 'Soon', 'expires_at' => now()->addDays(3)->toDateString()]);
        $this->uploadFor($employee, ['title' => 'Later', 'expires_at' => now()->addDays(90)->toDateString()]);

        $this->login('admin@flowsync.test');

        // If `expiring` bound as `{document}`, this would 404 on a perfectly
        // valid warning query — the same trap `reorder` had in P3.3.
        $body = $this->getJson('/api/hrms/documents/expiring?days=7')->assertOk()->json();

        $this->assertSame(7, $body['days']);
        $this->assertSame(['Soon'], array_column($body['documents'], 'title'));

        $this->getJson('/api/hrms/documents/expiring?days=-1')->assertUnprocessable()
            ->assertJsonValidationErrors('days');
    }

    public function test_the_type_catalogue_lists_active_types_only(): void
    {
        $active = $this->makeType(['name' => 'Current Type', 'slug' => 'current-type']);
        $retired = $this->makeType(['name' => 'Retired Type', 'slug' => 'retired-type', 'is_active' => false]);

        $this->login('admin@flowsync.test');

        $slugs = $this->getJson('/api/hrms/documents/types')->assertOk()->json('document_types.*.slug');

        $this->assertContains($active->slug, $slugs);
        $this->assertContains('passport', $slugs, 'The seeded catalogue travels with the endpoint.');
        $this->assertNotContains($retired->slug, $slugs);

        $this->getJson("/api/hrms/documents/types/{$active->id}")->assertOk()
            ->assertJsonPath('document_type.category', 'identity');
        $this->getJson("/api/hrms/documents/types/{$retired->id}")->assertNotFound();

        $this->getJson('/api/hrms/documents/types?category=astrology')->assertUnprocessable()
            ->assertJsonValidationErrors('category');
    }

    public function test_an_upload_without_a_file_is_a_422_with_the_file_blamed(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Fileless');

        $this->postJson('/api/hrms/documents', [
            'employee_id' => $employee->id,
            'document_type_id' => $this->makeType()->id,
            'title' => 'No file attached',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    // ------------------------------------------------------------ helpers

    /**
     * HTTP login, then reconnect the tenant.
     *
     * The login request resolves the tenant from the central routing index and
     * therefore runs on the central connection; anything the test then does
     * directly against a model would read `iso_system` and find no
     * `employee_documents` table. HTTP-only tests do not notice this, which is
     * why the reconnect is the login helper’s job here rather than every test’s.
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
            'name' => "Document User {$sequence}",
            'email' => "document.api.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Document API Role {$sequence}",
            'slug' => "document-api-role-{$sequence}",
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
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-API-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function makeType(array $overrides = []): DocumentType
    {
        static $sequence = 0;

        $sequence++;

        return DocumentType::create([
            'name' => "API Type {$sequence}",
            'slug' => "api-type-{$sequence}",
            'category' => 'identity',
            'is_mandatory' => false,
            'requires_expiry' => false,
            'retention_months' => null,
            'is_sensitive' => false,
            'position' => $sequence * 10,
            'is_active' => true,
            'is_system' => false,
            ...$overrides,
        ]);
    }

    /**
     * A stored row without going through HTTP: the upload endpoint itself is
     * under test elsewhere in this file, and fixtures that depend on it would
     * test the fixture rather than the policy.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function uploadFor(Employee $employee, array $overrides = []): EmployeeDocument
    {
        static $sequence = 0;

        $sequence++;

        $path = "hrms/{$this->acme()->id}/{$employee->id}/fixture-{$sequence}.pdf";
        Storage::disk('local')->put($path, 'fixture-bytes');

        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'document_type_id' => $this->makeType()->id,
            'title' => "Fixture {$sequence}",
            'file_disk' => 'local',
            'file_path' => $path,
            'original_name' => "fixture-{$sequence}.pdf",
            'mime' => 'application/pdf',
            'size' => 13,
            'status' => 'pending',
            'visibility' => 'hr',
            'confidential' => false,
            'source' => 'hr',
            ...$overrides,
        ]);
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
