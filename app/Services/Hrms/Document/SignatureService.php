<?php

namespace App\Services\Hrms\Document;

use App\Models\Hrms\Document\DocumentSignature;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Internal e-signature evidence (F6.7). See the migration for what is and is not claimed. */
class SignatureService
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function request(EmployeeDocument $document, Employee $signer, ?string $message, User $actor): DocumentSignature
    {
        if ($signer->user_id === null) {
            throw ValidationException::withMessages(['employee_id' => __('That person has no login to sign with.')]);
        }
        if (DocumentSignature::query()->where('document_id', $document->id)->where('employee_id', $signer->id)->whereIn('status', ['pending', 'signed'])->exists()) {
            throw ValidationException::withMessages(['form' => __('This document already has a signature request for that person.')]);
        }
        $this->hash($document); // fail now if the file is unreadable, not at signing time

        $sig = DocumentSignature::create([
            'document_id' => $document->id, 'employee_id' => $signer->id, 'requested_by_user_id' => $actor->id, 'status' => 'pending',
            'verification_code' => 'fsig_'.$this->tenant->currentId().'_'.Str::random(32), 'message' => $message !== null ? mb_substr(trim($message), 0, 500) : null,
        ]);
        $this->audit->log($sig, 'signature.requested', null, ['document_id' => $document->id, 'employee_id' => $signer->id], $actor);

        return $sig;
    }

    /** @throws ValidationException on a wrong password, an empty name, or a file that changed since the request */
    public function sign(DocumentSignature $sig, User $user, string $typedName, string $password, ?string $ip, ?string $userAgent): DocumentSignature
    {
        $this->requireSigner($sig, $user);

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => __('That password is not correct.')]);
        }
        if (trim($typedName) === '') {
            throw ValidationException::withMessages(['typed_name' => __('Type your full name to sign.')]);
        }

        $sha = $this->hash($sig->document);
        $signedAt = now();
        $receipt = hash('sha256', implode('|', [$sig->verification_code, $sha, $user->id, $signedAt->toIso8601String(), $ip]));

        $sig->update([
            'status' => 'signed', 'typed_name' => mb_substr(trim($typedName), 0, 255), 'document_sha256' => $sha, 'receipt_hash' => $receipt,
            'signed_ip' => $ip, 'signed_user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, 'signed_at' => $signedAt,
        ]);
        $this->audit->log($sig, 'signature.signed', ['status' => 'pending'], ['document_id' => $sig->document_id, 'sha256' => $sha], $user);

        return $sig->refresh();
    }

    public function decline(DocumentSignature $sig, User $user, string $reason): DocumentSignature
    {
        $this->requireSigner($sig, $user);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Say why you are declining.')]);
        }

        $sig->update(['status' => 'declined', 'decline_reason' => mb_substr(trim($reason), 0, 500)]);
        $this->audit->log($sig, 'signature.declined', ['status' => 'pending'], null, $user);

        return $sig->refresh();
    }

    public function cancel(DocumentSignature $sig, User $actor): DocumentSignature
    {
        if (! $sig->isPending()) {
            throw ValidationException::withMessages(['form' => __('Only a waiting request can be cancelled.')]);
        }
        $sig->update(['status' => 'cancelled']);
        $this->audit->log($sig, 'signature.cancelled', ['status' => 'pending'], null, $actor);

        return $sig->refresh();
    }

    /** @return array{valid: bool, intact: bool} receipt consistent / file unchanged since signing */
    public function check(DocumentSignature $sig): array
    {
        if ($sig->status !== 'signed') {
            return ['valid' => false, 'intact' => false];
        }
        $expected = hash('sha256', implode('|', [$sig->verification_code, $sig->document_sha256, $sig->employee->user_id, $sig->signed_at->toIso8601String(), $sig->signed_ip]));

        return [
            'valid' => hash_equals($sig->receipt_hash, $expected),
            'intact' => rescue(fn () => hash_equals($sig->document_sha256, $this->hash($sig->document)), false, false),
        ];
    }

    private function requireSigner(DocumentSignature $sig, User $user): void
    {
        if (! $sig->isPending()) {
            throw ValidationException::withMessages(['form' => __('This request has already been answered.')]);
        }
        if ($sig->employee?->user_id !== $user->id) {
            throw ValidationException::withMessages(['form' => __('Only the person asked to sign can answer this request.')]);
        }
    }

    private function hash(EmployeeDocument $document): string
    {
        $disk = Storage::disk($document->file_disk ?: 'local');
        if (! $disk->exists($document->file_path)) {
            throw ValidationException::withMessages(['form' => __('The document file could not be read.')]);
        }

        return hash('sha256', (string) $disk->get($document->file_path));
    }
}
