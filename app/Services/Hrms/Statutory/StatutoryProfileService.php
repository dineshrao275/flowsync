<?php

declare(strict_types=1);

namespace App\Services\Hrms\Statutory;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Statutory\StatutoryProfile;
use App\Models\User;
use App\Services\HrmsAuditLogger;

/**
 * Statutory/HRMS — one person's identifiers, masked by default.
 *
 * Reads return masked values plus `has_*` booleans; the full aadhaar is
 * never stored (only its last four survive the write), and the one read
 * that returns cleartext — `reveal()` — is manage-gated in the controller
 * and writes its access row here, listing only the fields that were
 * actually populated. Writes audit identifiers (field names), never values.
 */
class StatutoryProfileService
{
    public function __construct(
        private readonly StatutoryIdentifierMask $mask,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Create or replace one person's profile. `aadhaar` arrives full and
     * leaves as four digits; `bank_account` arrives clear and leaves
     * encrypted (the model's cast). Unknown keys never reach the model —
     * the request whitelists, and this picks explicitly anyway.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsert(Employee $employee, array $data, ?User $actor = null): StatutoryProfile
    {
        // An absent key keeps what is stored (the SPA form is blank, because masked values are never
        // submitted back); an explicit null clears it.
        $columns = [
            'pan' => 'pan',
            'uan' => 'uan',
            'esi_number' => 'esi_number',
            'pf_number' => 'pf_number',
            'pt_state' => 'pt_state',
            'bank_name' => 'bank_name',
            'bank_account' => 'bank_account_encrypted',
            'bank_ifsc' => 'bank_ifsc',
            'tax_declaration' => 'tax_declaration',
            'declarations' => 'declarations',
        ];

        $attributes = [];

        foreach ($columns as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        if (array_key_exists('aadhaar', $data)) {
            $attributes['aadhaar_last4'] = $data['aadhaar'] === null ? null : substr((string) $data['aadhaar'], -4);
        }

        if (array_key_exists('lwf_registration', $data)) {
            $attributes['lwf_registration'] = (bool) $data['lwf_registration'];
        }

        $profile = StatutoryProfile::query()->firstOrNew(['employee_id' => $employee->id]);
        $isNew = ! $profile->exists;
        $profile->fill($attributes);
        $profile->save();

        $this->audit->log($profile->refresh(), $isNew ? 'statutory.profile_created' : 'statutory.profile_updated', null, [
            'employee_id' => $employee->id,
            'fields' => $this->populatedColumns($profile),
        ], $actor);

        return $profile->refresh();
    }

    /**
     * The masked read: every identifier masked, every presence boolean
     * explicit, non-identifying columns as stored. No access row — masked
     * reads are ordinary reads, and a ledger of them would bury the
     * reveals.
     *
     * @return array<string, mixed>
     */
    public function present(StatutoryProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'employee_id' => $profile->employee_id,
            'pan' => $this->mask->pan($profile->pan),
            'aadhaar_last4' => $this->mask->aadhaarLast4($profile->aadhaar_last4),
            'uan' => $this->mask->identifier($profile->uan),
            'esi_number' => $this->mask->identifier($profile->esi_number),
            'pf_number' => $this->mask->identifier($profile->pf_number),
            'bank_account' => $this->mask->bankAccount($profile->bank_account_encrypted),
            'has_pan' => $profile->pan !== null && $profile->pan !== '',
            'has_aadhaar' => $profile->aadhaar_last4 !== null && $profile->aadhaar_last4 !== '',
            'has_uan' => $profile->uan !== null && $profile->uan !== '',
            'has_esi_number' => $profile->esi_number !== null && $profile->esi_number !== '',
            'has_pf_number' => $profile->pf_number !== null && $profile->pf_number !== '',
            'has_bank_account' => $profile->bank_account_encrypted !== null && $profile->bank_account_encrypted !== '',
            'pt_state' => $profile->pt_state,
            'lwf_registration' => $profile->lwf_registration,
            'bank_name' => $profile->bank_name,
            'bank_ifsc' => $profile->bank_ifsc,
            'tax_declaration' => $profile->tax_declaration,
            'declarations' => $profile->declarations,
            'verified_at' => $profile->verified_at?->toIso8601String(),
        ];
    }

    /**
     * The cleartext read: populated identifiers unmasked, logged with the
     * populated field names. A field that was never filed is returned as
     * nothing — the log answers what the reader could see, and an empty
     * field was visible as nothing.
     *
     * @return array<string, mixed>
     */
    public function reveal(StatutoryProfile $profile, User $reader, ?string $ipAddress): array
    {
        $fields = $this->populatedColumns($profile);

        $this->audit->accessed(
            (new StatutoryProfile)->getMorphClass(),
            $profile->id,
            DataAccessAction::View,
            $fields,
            $reader,
            $ipAddress,
        );

        return [
            'id' => $profile->id,
            'employee_id' => $profile->employee_id,
            'pan' => $profile->pan,
            'aadhaar_last4' => $profile->aadhaar_last4,
            'uan' => $profile->uan,
            'esi_number' => $profile->esi_number,
            'pf_number' => $profile->pf_number,
            'bank_account' => $profile->bank_account_encrypted,
        ];
    }

    /**
     * The identifier columns actually populated on this row — the access
     * log's field list and the reveal's contract share it.
     *
     * @return list<string>
     */
    private function populatedColumns(StatutoryProfile $profile): array
    {
        $populated = [];

        foreach (StatutoryIdentifierMask::REVEALABLE_COLUMNS as $column) {
            $value = $profile->{$column};

            if ($value === null || $value === '') {
                continue;
            }

            $populated[] = $column;
        }

        return $populated;
    }
}
