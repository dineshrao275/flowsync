<?php

namespace App\Services\Hrms\Statutory;

/**
 * Statutory/HRMS — masks for government identifiers and bank accounts.
 *
 * The SensitiveFieldRedactor shape, one context over: each primitive is a
 * named, independently testable rule, and the service decides which fields
 * were populated for the access log. Every identifier keeps its last four —
 * enough to tell two cards apart and confirm one is on file — and nothing
 * else. The full aadhaar never reaches a mask because it never reaches the
 * database: only its last four are stored.
 */
class StatutoryIdentifierMask
{
    /**
     * Identifier columns a reveal may return unmasked.
     *
     * The access log's field list and the reveal shape share this source, so
     * a value cannot be readable yet missing from the row that says who
     * read it.
     *
     * @var list<string>
     */
    public const REVEALABLE_COLUMNS = [
        'pan',
        'aadhaar_last4',
        'uan',
        'esi_number',
        'pf_number',
        'bank_account_encrypted',
    ];

    /**
     * `ABCDE1234F` -> `XXXXX1234F`.
     *
     * The plan's shape: all but the last five masked with X. PANs are
     * fixed-length, so the five survivors confirm the card without naming
     * it — and the shape matches the spec example rather than inventing a
     * second convention beside the bank mask.
     */
    public function pan(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (strlen($value) <= 5) {
            return str_repeat('X', strlen($value));
        }

        return str_repeat('X', strlen($value) - 5).substr($value, -5);
    }

    /**
     * `101234567890` -> `********7890`.
     *
     * UAN, ESI and PF numbers keep their last four, X-masked — the bank
     * length, without the bank's fixed-width stars (these lengths vary, so
     * a length-preserving mask says less about the account).
     */
    public function identifier(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (strlen($value) <= 4) {
            return str_repeat('X', strlen($value));
        }

        return str_repeat('X', strlen($value) - 4).substr($value, -4);
    }

    /**
     * `123456789012` -> `****9012`.
     *
     * Four stars, always — the length of an account number is itself a
     * fact about the account, so the mask is fixed-width rather than
     * length-preserving.
     */
    public function bankAccount(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (strlen($value) <= 4) {
            return '****';
        }

        return '****'.substr($value, -4);
    }

    /**
     * A stored last-four displayed as filed: already minimal, so no mask —
     * the value on the row is the value on the screen.
     */
    public function aadhaarLast4(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
