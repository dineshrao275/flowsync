<?php

namespace Tests\Feature;

use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsAuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.12 — replacing a document keeps the old file: a new row takes over
 * `is_current`, lists and expiry read only the current version, deleting the
 * newest promotes its predecessor, and carried-over attributes cannot be
 * downgraded by a replacement.
 */
class HrmsDocumentVersionTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setAcmeModules(['hrms.core', 'hrms.documents']);
    }

    private function filed(Employee $employee, array $overrides = []): EmployeeDocument
    {
        $type = DocumentType::query()->where('is_active', true)->firstOrFail();
        $path = "hrms/{$this->acme()->id}/{$employee->id}/seed-".uniqid().'.pdf';
        Storage::disk('local')->put($path, 'bytes');

        return EmployeeDocument::create([
            'employee_id' => $employee->id, 'document_type_id' => $type->id, 'title' => 'Passport',
            'file_disk' => 'local', 'file_path' => $path, 'original_name' => 'passport.pdf', 'mime' => 'application/pdf',
            'size' => 5, 'status' => 'verified', 'visibility' => 'hr', 'confidential' => true, 'source' => 'hr',
            ...$overrides,
        ]);
    }

    public function test_a_new_version_supersedes_and_inherits_attributes(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Versioned');
        $v1 = $this->filed($employee);

        $res = $this->postJson("/api/hrms/documents/{$v1->id}/versions", [
            'file' => UploadedFile::fake()->create('passport-2.pdf', 20, 'application/pdf'),
            'expires_at' => '2031-01-01',
        ])->assertCreated()->assertJsonPath('document.version', 2)->assertJsonPath('document.status', 'pending');

        $v2 = EmployeeDocument::findOrFail($res->json('document.id'));
        $this->assertSame($v1->id, $v2->supersedes_id);
        $this->assertTrue($v2->confidential, 'A replacement cannot turn a confidential file public.');
        $this->assertSame('Passport', $v2->title);
        $this->assertFalse($v1->fresh()->is_current);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'document.version_added')->exists());

        $this->getJson("/api/hrms/documents/{$v1->id}/versions")->assertOk()->assertJsonCount(2, 'versions')
            ->assertJsonPath('versions.0.version', 1)->assertJsonPath('versions.1.is_current', true);

        // Lists show the current version only.
        $ids = $this->getJson('/api/hrms/documents')->assertOk()->json('documents.*.id');
        $this->assertContains($v2->id, $ids);
        $this->assertNotContains($v1->id, $ids);
    }

    public function test_only_the_current_version_can_be_replaced(): void
    {
        $this->loginAdmin();
        $v1 = $this->filed($this->makeEmployee('Twice'));
        $file = fn () => UploadedFile::fake()->create('again.pdf', 10, 'application/pdf');

        $this->postJson("/api/hrms/documents/{$v1->id}/versions", ['file' => $file()])->assertCreated();
        $this->postJson("/api/hrms/documents/{$v1->id}/versions", ['file' => $file()])
            ->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    public function test_expiry_ignores_replaced_versions(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Expiring');
        $old = $this->filed($employee, ['expires_at' => now()->subDay()->toDateString()]);
        $this->postJson("/api/hrms/documents/{$old->id}/versions", [
            'file' => UploadedFile::fake()->create('renewed.pdf', 10, 'application/pdf'), 'expires_at' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $this->assertSame(0, EmployeeDocument::query()->expiringBy(now())->count());
    }

    public function test_deleting_the_newest_version_promotes_the_previous_one(): void
    {
        $this->loginAdmin();
        $v1 = $this->filed($this->makeEmployee('Rollback'));
        $v2Id = $this->postJson("/api/hrms/documents/{$v1->id}/versions", [
            'file' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'),
        ])->assertCreated()->json('document.id');

        $this->deleteJson("/api/hrms/documents/{$v2Id}")->assertOk();

        $this->assertTrue($v1->fresh()->is_current);
    }

    public function test_replacing_needs_upload_rights_and_the_module(): void
    {
        $v1 = $this->filed($this->makeEmployee('Guarded'));
        $file = fn () => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');

        $this->actAs($this->userWith(['hrms.view', 'hrms.documents.view', 'hrms.documents.view_sensitive']));
        $this->postJson("/api/hrms/documents/{$v1->id}/versions", ['file' => $file()])->assertForbidden();

        $this->setAcmeModules(['hrms.core']);
        $this->loginAdmin();
        $this->postJson("/api/hrms/documents/{$v1->id}/versions", ['file' => $file()])->assertForbidden();
    }
}
