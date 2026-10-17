<?php

namespace App\Services\Hrms\Document;

use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Document\LetterTemplate;
use App\Models\Hrms\Employee\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hrms\DocumentService;
use App\Support\Pdf\SimplePdf;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Letters from templates (F6.2). Merge fields are plain `{{name}}` substitutions — no expression language, so a
 * template author cannot run anything. A field that has no value is an error (a letter that says "{{last_working_day}}"
 * must never reach an employee). The PDF is stored through the normal document service, so it gets the same
 * type, visibility, audit trail and signed download as any other HR document.
 */
class LetterService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, string>  $extra
     *
     * @throws ValidationException on a missing field, or when the template's document type does not exist
     */
    public function generate(Employee $employee, LetterTemplate $template, array $extra, User $actor): EmployeeDocument
    {
        $type = DocumentType::query()->where('slug', $template->document_type_slug)->first()
            ?? throw ValidationException::withMessages(['template' => __('That template names a document type this tenant does not have.')]);

        $text = $this->render($template->body, $this->values($employee, $extra));
        $bytes = $this->pdf($template->name, $text);

        $tmp = tempnam(sys_get_temp_dir(), 'letter');
        file_put_contents($tmp, $bytes);

        try {
            $file = new UploadedFile($tmp, Str::slug($template->name).'-'.Str::slug($employee->name).'.pdf', 'application/pdf', null, true);

            return $this->documents->upload($employee, $type, $file, [
                'title' => $template->name.' — '.now()->format('j M Y'),
                'source' => 'hr',
                'visibility' => 'employee',
                'issued_at' => now()->toDateString(),
            ], $actor);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param  array<string, string>  $values
     *
     * @throws ValidationException listing every unfilled field
     */
    public function render(string $body, array $values): string
    {
        $missing = [];
        $out = preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function (array $m) use ($values, &$missing): string {
            $key = strtolower($m[1]);
            if (! isset($values[$key]) || trim((string) $values[$key]) === '') {
                $missing[$key] = true;

                return $m[0];
            }

            return (string) $values[$key];
        }, $body);

        if ($missing !== []) {
            throw ValidationException::withMessages(['fields' => __('Fill in: :fields.', ['fields' => implode(', ', array_keys($missing))])]);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function values(Employee $employee, array $extra): array
    {
        $department = $employee->department()->first();
        $designation = $employee->designation()->first();

        $base = [
            'employee_name' => $employee->displayName(),
            'employee_code' => (string) $employee->employee_code,
            'designation' => (string) ($designation?->name ?? $employee->getAttribute('designation') ?? ''),
            'department' => (string) ($department?->name ?? ''),
            'joining_date' => $employee->joining_date?->format('j F Y') ?? '',
            'today' => now()->format('j F Y'),
            'company' => (string) (($id = $this->context->currentId()) ? Tenant::find($id)?->name : ''),
        ];

        $extras = [];
        foreach ($extra as $key => $value) {
            if (preg_match('/^[a-z0-9_]{1,40}$/i', (string) $key)) {
                $extras[strtolower((string) $key)] = mb_substr((string) $value, 0, 200);
            }
        }

        return $extras + $base; // the record's own facts win over a typed value of the same name
    }

    private function pdf(string $title, string $text): string
    {
        $pdf = (new SimplePdf)->line($title, 16, true)->gap(10);

        foreach (preg_split('/\R/', $text) as $paragraph) {
            if (trim($paragraph) === '') {
                $pdf->gap(8);

                continue;
            }
            foreach (explode("\n", wordwrap($paragraph, 90, "\n", true)) as $line) {
                $pdf->line($line, 11);
            }
        }

        return $pdf->output();
    }
}
