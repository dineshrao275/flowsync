<?php

namespace Tests\Feature;

use App\Enums\Hrms\DocumentStatus;
use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\DocumentService;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P13.2 — the employee-document service and its signed download.
 *
 * The service rules are covered directly because no authenticated document
 * endpoints ship until P13.3. What is worth protecting here is the part only
 * the service can see: active-type and metadata validation, the verified /
 * rejected / expired state machine, expiry by command, file-plus-row delete,
 * and the session-free download — including the signed tenant scope and the
 * extra permission plus access row for a confidential file.
 */
class HrmsDocumentServiceTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(TenantContext::class)->setTenantId($this->acme()->id);
    }

    public function test_upload_stores_the_file_and_a_pending_row(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType();
        $actor = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $document = app(DocumentService::class)->upload(
            $employee,
            $type,
            $this->pdf('passport.pdf'),
            ['title' => 'Passport'],
            $actor,
        );

        $this->assertSame(DocumentStatus::Pending->value, $document->status->value);
        $this->assertSame($actor->id, $document->created_by);
        $this->assertTrue(Storage::disk('local')->exists($document->file_path));
        $this->assertStringStartsWith("hrms/{$this->acme()->id}/{$employee->id}/", $document->file_path);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'document.uploaded')->exists());
        $logged = HrmsAuditLog::query()->where('action', 'document.uploaded')->latest('id')->firstOrFail();

        // A title can name the condition or the account, so the ledger records
        // the row and type ids rather than a second copy of the sensitive data.
        $this->assertArrayNotHasKey('title', $logged->data['after'] ?? []);

        $presented = app(DocumentService::class)->present($document, $actor);

        $this->assertSame('Passport', $presented['title']);
        $this->assertSame('identity', $presented['type']['category']);
        $this->assertStringContainsString('tenant='.$this->acme()->id, $presented['download_url']);
        $this->assertStringContainsString('actor='.$actor->id, $presented['download_url']);
    }

    public function test_a_retired_document_type_cannot_receive_uploads(): void
    {
        $this->expectExceptionMessage('retired');

        app(DocumentService::class)->upload(
            $this->makeEmployee(),
            $this->makeType(['is_active' => false]),
            $this->pdf(),
            ['title' => 'Passport'],
        );
    }

    public function test_a_file_outside_the_allow_list_is_a_file_error_not_a_500(): void
    {
        try {
            app(DocumentService::class)->upload(
                $this->makeEmployee(),
                $this->makeType(),
                UploadedFile::fake()->create('installer.exe', 100, 'application/x-msdownload'),
                ['title' => 'Installer'],
            );

            $this->fail('An executable should not become an employee document.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    public function test_upload_metadata_is_validated_by_field(): void
    {
        try {
            app(DocumentService::class)->upload(
                $this->makeEmployee(),
                $this->makeType(),
                $this->pdf(),
                ['title' => '', 'source' => 'telepathy', 'expires_at' => 'not-a-date'],
            );

            $this->fail('Bad upload metadata should not create a row.');
        } catch (ValidationException $exception) {
            // Title is checked first because a document without a recognisable
            // title is unusable even if everything else is right.
            $this->assertArrayHasKey('title', $exception->errors());
        }
    }

    public function test_verify_reject_and_illegal_transitions(): void
    {
        $service = app(DocumentService::class);
        $document = $service->upload($this->makeEmployee(), $this->makeType(), $this->pdf(), ['title' => 'Passport']);
        $actor = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $verified = $service->verify($document, $actor);

        $this->assertSame(DocumentStatus::Verified->value, $verified->status->value);
        $this->assertSame($actor->id, $verified->verified_by_user_id);
        $this->assertNotNull($verified->verified_at);

        $rejected = $service->reject($verified, 'The scan is unreadable.', $actor);

        $this->assertSame(DocumentStatus::Rejected->value, $rejected->status->value);
        $this->assertSame('The scan is unreadable.', $rejected->rejection_reason);

        try {
            $service->verify($rejected, $actor);
            $this->fail('A rejected row is closed; fixing it means a new upload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    public function test_reject_needs_a_reason_the_employee_can_act_on(): void
    {
        $this->expectException(ValidationException::class);

        app(DocumentService::class)->reject(
            app(DocumentService::class)->upload($this->makeEmployee(), $this->makeType(), $this->pdf(), ['title' => 'Passport']),
            '  ',
        );
    }

    public function test_the_expiry_command_closes_only_live_past_due_rows(): void
    {
        $service = app(DocumentService::class);
        $employee = $this->makeEmployee();
        $type = $this->makeType();
        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $pastDue = $service->upload($employee, $type, $this->pdf('old.pdf'), [
            'title' => 'Old passport',
            'expires_at' => $yesterday,
        ]);
        $service->verify($pastDue);

        $future = $service->upload($employee, $type, $this->pdf('new.pdf'), [
            'title' => 'New passport',
            'expires_at' => $tomorrow,
        ]);
        $service->verify($future);

        $closed = $service->upload($employee, $type, $this->pdf('rejected.pdf'), [
            'title' => 'Rejected passport',
            'expires_at' => $yesterday,
        ]);
        $service->reject($closed, 'Wrong document.');

        $this->assertSame(0, Artisan::call('hrms:documents-expiry', ['--tenant' => $this->acme()->id]));

        $this->assertSame(DocumentStatus::Expired->value, $pastDue->fresh()->status->value);
        $this->assertSame(DocumentStatus::Verified->value, $future->fresh()->status->value);
        $this->assertSame(DocumentStatus::Rejected->value, $closed->fresh()->status->value);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'document.expired')->exists());
    }

    public function test_expire_soon_lists_live_documents_inside_the_window(): void
    {
        $service = app(DocumentService::class);
        $employee = $this->makeEmployee();
        $type = $this->makeType();

        $soon = $service->upload($employee, $type, $this->pdf('soon.pdf'), [
            'title' => 'Soon',
            'expires_at' => now()->addDays(3)->toDateString(),
        ]);
        $later = $service->upload($employee, $type, $this->pdf('later.pdf'), [
            'title' => 'Later',
            'expires_at' => now()->addDays(30)->toDateString(),
        ]);

        $rows = $service->expireSoon($employee, 7);

        $this->assertTrue($rows->contains('id', $soon->id));
        $this->assertFalse($rows->contains('id', $later->id));
    }

    public function test_delete_removes_the_file_and_hides_the_row(): void
    {
        $service = app(DocumentService::class);
        $document = $service->upload($this->makeEmployee(), $this->makeType(), $this->pdf(), ['title' => 'Passport']);
        $path = $document->file_path;

        $service->delete($document);

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertTrue($document->fresh()->trashed());
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'document.deleted')->exists());
    }

    public function test_a_signed_download_works_without_a_session_on_the_central_connection(): void
    {
        $service = app(DocumentService::class);
        $document = $service->upload($this->makeEmployee(), $this->makeType(), $this->pdf(), ['title' => 'Passport']);
        $reader = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $url = $service->present($document, $reader)['download_url'];

        // A fresh browser tab carries no tenant state: no session, and the
        // default connection pointing at the central system DB. The signature
        // alone has to be enough to find the tenant and the file.
        $this->flushSession();
        DB::setDefaultConnection(app(TenantDatabaseManager::class)->centralConnectionName());

        $response = $this->get($url);

        $response->assertOk()->assertHeader('content-disposition', 'attachment; filename=passport.pdf');
        $this->assertSame(Storage::disk('local')->get($document->file_path), $response->streamedContent());

        // A reader-less link is refused even with a valid signature: the
        // stream names no reader, and anonymous holders get nothing.
        $anonymous = URL::temporarySignedRoute('hrms.documents.download', now()->addHour(), [
            'document' => $document->id,
            'tenant' => $this->acme()->id,
        ]);
        $this->get($anonymous)->assertForbidden();

        // A document id from another tenant must not resolve here: the ids are
        // tenant-local, so only the signed tenant selects the database.
        $foreign = URL::temporarySignedRoute('hrms.documents.download', now()->addHour(), [
            'document' => $document->id,
            'tenant' => $this->globex()->id,
            'actor' => $reader->id,
        ]);
        $this->get($foreign)->assertNotFound();

        // Editing the signed tenant invalidates the signature itself.
        $tampered = str_replace('tenant='.$this->acme()->id, 'tenant=999999', $url);
        $this->get($tampered)->assertForbidden();
    }

    public function test_a_confidential_download_needs_the_sensitive_permission_and_logs_access(): void
    {
        $service = app(DocumentService::class);
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $reader = $this->userWith(['hrms.view']);
        $document = $service->upload(
            $this->makeEmployee(),
            $this->makeType(),
            $this->pdf(),
            ['title' => 'Bank proof', 'confidential' => true],
            $admin,
        );

        $allowedUrl = $service->present($document, $admin)['download_url'];
        $deniedUrl = $service->present($document, $reader)['download_url'];

        $this->flushSession();
        DB::setDefaultConnection(app(TenantDatabaseManager::class)->centralConnectionName());

        $this->get($deniedUrl)->assertForbidden();

        $this->get($allowedUrl)->assertOk()->assertHeader('content-disposition', 'attachment; filename=passport.pdf');

        $this->connectTenant('acme');

        $this->assertTrue(HrmsDataAccessLog::query()
            ->where('model', (new EmployeeDocument)->getMorphClass())
            ->where('record_id', $document->id)
            ->where('action', 'download')
            ->where('actor_user_id', $admin->id)
            ->exists(), 'A confidential download is a sensitive read and belongs in the access ledger.');
    }

    public function test_a_soft_deleted_document_is_a_404_on_its_signed_url(): void
    {
        $service = app(DocumentService::class);
        $document = $service->upload($this->makeEmployee(), $this->makeType(), $this->pdf(), ['title' => 'Passport']);
        $url = $service->present($document)['download_url'];

        $service->delete($document);

        $this->get($url)->assertNotFound();
    }

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-DOC-'.$sequence,
            'name' => "Document Employee {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function makeType(array $overrides = []): DocumentType
    {
        static $sequence = 0;

        $sequence++;

        return DocumentType::create([
            'name' => "Identity {$sequence}",
            'slug' => "identity-{$sequence}",
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

    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Document User {$sequence}",
            'email' => "document.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Document Role {$sequence}",
            'slug' => "document-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function pdf(string $name = 'passport.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }
}
