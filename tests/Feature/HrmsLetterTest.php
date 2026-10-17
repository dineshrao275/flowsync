<?php

namespace Tests\Feature;

use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F6.2 — a template with merge fields becomes a PDF filed in the employee's documents. */
class HrmsLetterTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.documents']);
        $this->emp = $this->makeEmployee('Lena Leaver', ['joining_date' => '2024-01-15']);
        $this->actAs($this->userWith(['hrms.view', 'hrms.documents.manage']));
    }

    public function test_starters_generate_a_stored_pdf_and_missing_fields_are_refused(): void
    {
        $templates = $this->postJson('/api/hrms/letter-templates/starters')->assertOk()->json('templates');
        $experience = collect($templates)->firstWhere('slug', 'experience-letter');

        // {{last_working_day}} is not a record fact, so it has to be supplied.
        $this->postJson("/api/hrms/employees/{$this->emp->id}/letters", ['template_id' => $experience['id']])
            ->assertStatus(422)->assertJsonValidationErrors('fields');

        $this->postJson("/api/hrms/employees/{$this->emp->id}/letters", ['template_id' => $experience['id'], 'fields' => ['last_working_day' => '30 June 2026', 'designation' => 'Analyst', 'department' => 'Finance']])->assertCreated();

        $doc = EmployeeDocument::query()->where('employee_id', $this->emp->id)->firstOrFail();
        $this->assertSame('application/pdf', $doc->mime);
        $this->assertStringStartsWith('%PDF', file_get_contents(\Storage::disk('local')->path($doc->file_path)));
    }

    public function test_it_needs_document_management(): void
    {
        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/letter-templates')->assertForbidden();
    }
}
