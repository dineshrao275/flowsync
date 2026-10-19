<?php

namespace Tests\Feature;

use App\Models\Hrms\Document\DocumentSignature;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F6.7 — internal e-signature: request, sign with a re-entered password, public verification, tamper detection. */
class HrmsSignatureTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private User $hr;

    private User $worker;

    private Employee $emp;

    private int $docId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.documents']);
        $this->hr = $this->userWith(['hrms.view', 'hrms.documents.manage']);
        $this->worker = $this->userWith(['hrms.view']);
        $this->worker->forceFill(['password' => Hash::make('secret-pass')])->save();
        $this->emp = $this->makeEmployee('Sig Signer', ['user_id' => $this->worker->id]);

        $doc = app(DocumentService::class)->upload($this->emp, DocumentType::query()->where('slug', 'employment_contract')->firstOrFail(),
            UploadedFile::fake()->createWithContent('contract.pdf', "%PDF-1.4\nthe terms\n%%EOF"), ['title' => 'Contract', 'visibility' => 'employee'], $this->hr);
        $this->docId = $doc->id;
    }

    private function request(): array
    {
        $this->actAs($this->hr);

        return $this->postJson('/api/hrms/signatures', ['document_id' => $this->docId, 'employee_id' => $this->emp->id])->assertCreated()->json('signature');
    }

    public function test_the_signer_signs_and_anyone_can_verify_the_receipt(): void
    {
        $sig = $this->request();
        $this->assertSame('pending', $sig['status']);

        $this->actAs($this->worker);
        $this->postJson("/api/hrms/signatures/{$sig['id']}/sign", ['typed_name' => 'Sig Signer', 'password' => 'wrong'])->assertStatus(422);
        $signed = $this->postJson("/api/hrms/signatures/{$sig['id']}/sign", ['typed_name' => 'Sig Signer', 'password' => 'secret-pass'])->assertOk()->json('signature');
        $this->assertSame('signed', $signed['status']);

        auth()->logout();
        $this->flushSession();
        $this->getJson("/api/signatures/verify/{$signed['verification_code']}")->assertOk()
            ->assertJsonPath('valid', true)->assertJsonPath('intact', true)->assertJsonPath('signer', 'Sig Signer');
        $this->getJson('/api/signatures/verify/fsig_1_'.str_repeat('a', 32))->assertNotFound();
    }

    public function test_changing_the_file_afterwards_is_detected(): void
    {
        $sig = $this->request();
        $this->actAs($this->worker);
        $code = $this->postJson("/api/hrms/signatures/{$sig['id']}/sign", ['typed_name' => 'Sig Signer', 'password' => 'secret-pass'])->json('signature.verification_code');

        $path = DocumentSignature::query()->first()->document->file_path;
        Storage::disk('local')->put($path, 'tampered');

        $this->getJson("/api/signatures/verify/$code")->assertOk()->assertJsonPath('valid', true)->assertJsonPath('intact', false);
    }

    public function test_only_the_signer_answers_and_only_hr_requests(): void
    {
        $sig = $this->request();

        $this->actAs($this->hr);
        $this->postJson("/api/hrms/signatures/{$sig['id']}/sign", ['typed_name' => 'HR', 'password' => 'password'])->assertStatus(422);

        $this->actAs($this->worker);
        $this->postJson('/api/hrms/signatures', ['document_id' => $this->docId, 'employee_id' => $this->emp->id])->assertForbidden();
        $this->postJson("/api/hrms/signatures/{$sig['id']}/decline", ['reason' => 'Wrong terms'])->assertOk()->assertJsonPath('signature.status', 'declined');
    }
}
