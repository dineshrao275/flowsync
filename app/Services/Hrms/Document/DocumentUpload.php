<?php

namespace App\Services\Hrms\Document;

use App\Enums\Hrms\DocumentSource;
use App\Enums\Hrms\DocumentStatus;
use App\Enums\Hrms\DocumentVisibility;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\User;
use App\Services\Hrms\DocumentService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Document/HRMS — what may be stored, and where the bytes go.
 *
 * Split out of {@see DocumentService} because validation,
 * storage and metadata rules are a self-contained decision: the allow-list, the
 * file path, and the row the file describes. The database row itself is still
 * created by the service, so a stored file and its record are committed
 * together rather than by two writers that can disagree.
 */
class DocumentUpload
{
    /**
     * The same allow-list as task attachments, overridable per tenant through
     * `hrms_settings.documents.allowed_mimes` when that section exists.
     *
     * Public so the upload FormRequest validates the same list the service
     * enforces for direct callers: two copies of an allow-list is how an
     * endpoint and a command disagree about what a PDF is.
     *
     * @var list<string>
     */
    // SVG is deliberately absent: it is executable markup (stored XSS when
    // served inline), and nothing in the product needs vector uploads.
    public const ALLOWED_MIMES = [
        'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt',
        'pptx', 'txt', 'md', 'csv', 'zip', 'json',
    ];

    public const MAX_KILOBYTES = 10 * 1024;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Validate everything, store the bytes, and return both halves.
     *
     * The caller still creates the row in its own transaction: storage is
     * outside the database, so the row and the cleanup of a failed row belong
     * to the orchestrator rather than to this class.
     *
     * @param  array<string, mixed>  $meta
     * @return array{path: string, attributes: array<string, mixed>}
     *
     * @throws ValidationException on an inactive type, a bad file, or bad metadata
     */
    public function store(
        Employee $employee,
        DocumentType $type,
        UploadedFile $file,
        array $meta,
        ?User $actor,
    ): array {
        $this->requireActiveType($type);
        $this->validateFile($file);

        return [
            'path' => $this->storeFile($employee, $file),
            'attributes' => $this->attributes($employee, $type, $file, $meta, $actor),
        ];
    }

    private function requireActiveType(DocumentType $type): void
    {
        if (! $type->is_active) {
            throw ValidationException::withMessages(['document_type_id' => 'That document type has been retired. Pick a current one.']);
        }
    }

    /**
     * The effective allow-list: the tenant setting may only NARROW the server
     * list, never widen it — a tenant admin must not be able to re-enable an
     * executable type (svg) or introduce server-side ones (php/phar/phtml).
     *
     * @return list<string>
     */
    private function allowedMimes(): array
    {
        $settings = HrmsSetting::query()->find(HrmsSetting::SINGLETON_ID);
        $configured = $settings?->setting('documents.allowed_mimes');

        if (! is_array($configured) || $configured === []) {
            return self::ALLOWED_MIMES;
        }

        $narrowed = array_values(array_intersect($configured, self::ALLOWED_MIMES));

        return $narrowed === [] ? self::ALLOWED_MIMES : $narrowed;
    }

    private function validateFile(UploadedFile $file): void
    {
        Validator::make(
            ['file' => $file],
            ['file' => ['required', File::types($this->allowedMimes())->max(self::MAX_KILOBYTES)]],
        )->validate();
    }

    private function tenantId(): int
    {
        $tenantId = $this->context->currentId();

        if ($tenantId === null) {
            throw ValidationException::withMessages(['form' => 'Documents need an active tenant before anything can be stored.']);
        }

        return $tenantId;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function attributes(
        Employee $employee,
        DocumentType $type,
        UploadedFile $file,
        array $meta,
        ?User $actor,
    ): array {
        $title = trim((string) ($meta['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > 255) {
            throw ValidationException::withMessages(['title' => 'A document needs a short title the employee recognises.']);
        }

        $source = DocumentSource::tryFrom((string) ($meta['source'] ?? DocumentSource::Hr->value));
        $visibility = DocumentVisibility::tryFrom((string) ($meta['visibility'] ?? DocumentVisibility::Hr->value));

        if ($source === null) {
            throw ValidationException::withMessages(['source' => 'That source is not a way a document can arrive.']);
        }

        if ($visibility === null) {
            throw ValidationException::withMessages(['visibility' => 'That visibility does not name a real audience.']);
        }

        $issuedAt = $this->optionalDate($meta['issued_at'] ?? null, 'issued_at');
        $expiresAt = $this->optionalDate($meta['expires_at'] ?? null, 'expires_at');

        if ($issuedAt !== null && $expiresAt !== null && $expiresAt->lt($issuedAt)) {
            throw ValidationException::withMessages(['expires_at' => 'Expiry cannot come before the issue date.']);
        }

        return [
            'employee_id' => $employee->id,
            'document_type_id' => $type->id,
            'title' => $title,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?: null,
            'size' => (int) $file->getSize(),
            'file_disk' => 'local',
            'status' => DocumentStatus::Pending->value,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'visibility' => $visibility->value,
            'confidential' => (bool) ($meta['confidential'] ?? $type->is_sensitive),
            'source' => $source->value,
            'created_by' => $actor?->id,
        ];
    }

    private function storeFile(Employee $employee, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        if ($extension === '') {
            throw ValidationException::withMessages(['file' => 'The file needs an extension the allow-list can recognise.']);
        }

        // A UUID name, never the uploaded one: two “passport.pdf” uploads are
        // the current one and the replacement, and the original name stays on
        // the row where the employee recognises it.
        $path = $file->storeAs(
            "hrms/{$this->tenantId()}/{$employee->id}",
            (string) Str::uuid().'.'.$extension,
            ['disk' => 'local'],
        );

        if (! is_string($path)) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored.']);
        }

        return $path;
    }

    private function optionalDate(mixed $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $value instanceof Carbon ? $value : Carbon::parse((string) $value)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'That is not a date this row can carry.']);
        }
    }
}
