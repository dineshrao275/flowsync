<?php

namespace App\Support;

/**
 * Field-name-driven value masking shared by the HRMS audit ledger and the
 * platform audit feed (H-7: audit payload hardening follows the same rules
 * wherever the row lands).
 *
 * Masking is not optional and is keyed on the field *name*, never an
 * allow-list: a new sensitive column must not silently start logging its
 * value. Names are matched token-by-token rather than as raw substrings,
 * because `'esi'` is a substring of `designation`, `'pan'` of `company` and
 * `'pay'` of `repayment` — masking those would silently blank half the trail.
 * A short token like `pan` has to be a whole word or it is useless.
 */
final class AuditMask
{
    public const MASK = '***';

    /**
     * Attribute/field name tokens that must never be logged verbatim.
     *
     * @var list<string>
     */
    private const SENSITIVE_TOKENS = [
        'password', 'passwd', 'secret', 'token',
        'salary', 'salaries', 'wage', 'wages', 'pay', 'pays', 'payslip',
        'ctc', 'compensation', 'bonus', 'remuneration', 'emoluments',
        'bank', 'banking', 'iban', 'swift', 'account', 'acct',
        'pan', 'uan', 'esi', 'pf', 'ifsc', 'aadhaar', 'passport',
        'national', 'nid',
        'dob', 'birth', 'birthday',
        'address', 'phone', 'mobile', 'email',
        'medical', 'diagnosis', 'health',
        'ssn', 'sin', 'nino', 'cpf',
    ];

    /**
     * Multi-word field names that are sensitive as a phrase, matched against
     * the whole normalised name.
     *
     * @var list<string>
     */
    private const SENSITIVE_PHRASES = [
        'date_of_birth',
        'bank_account',
        'account_number',
        'national_id',
    ];

    /**
     * Secrets only — for identity records (login/logout rows), where the
     * account itself IS the record. Masking `email` there would produce an
     * audit trail that cannot answer who signed in; nothing else in the
     * identity payload should ever carry a secret anyway.
     *
     * @var list<string>
     */
    private const SECRET_TOKENS = [
        'password', 'passwd', 'secret', 'token', 'otp',
    ];

    /**
     * Replace sensitive values with a mask, recursively.
     *
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    public static function mask(?array $data, ?string $key = null): ?array
    {
        return self::apply($data, self::SENSITIVE_TOKENS, self::SENSITIVE_PHRASES, $key);
    }

    /**
     * Replace secret values with a mask, recursively — the identity-record
     * variant used by the platform auth audit rows.
     *
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    public static function maskSecrets(?array $data, ?string $key = null): ?array
    {
        return self::apply($data, self::SECRET_TOKENS, [], $key);
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     * @param  list<string>  $tokens
     * @param  list<string>  $phrases
     * @return array<array-key, mixed>|null
     */
    private static function apply(?array $data, array $tokens, array $phrases, ?string $key): ?array
    {
        if ($data === null) {
            return null;
        }

        $masked = [];

        foreach ($data as $name => $value) {
            $field = is_string($name) ? $name : $key;

            $masked[$name] = self::isSensitive($field, $tokens, $phrases)
                ? self::MASK
                : (is_array($value) ? self::apply($value, $tokens, $phrases, $field) : $value);
        }

        return $masked;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $phrases
     */
    private static function isSensitive(?string $field, array $tokens, array $phrases): bool
    {
        if ($field === null) {
            return false;
        }

        $name = self::normalise($field);

        if (in_array($name, $phrases, true)) {
            return true;
        }

        foreach (self::tokenize($name) as $token) {
            if (in_array($token, $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-case a field name and collapse separators into `_` tokens, so
     * `basic_salary`, `basicSalary` and `Basic Salary` all normalise the same.
     *
     * The two insertion rules are both needed: the first splits a camelCase
     * hump, the second splits an acronym run from a following word. Without
     * the second, `ESI Number` becomes `E_SI_Number` and the `esi` token is
     * lost — which would silently start logging ESI numbers verbatim.
     */
    private static function normalise(string $field): string
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $field) ?? $field;
        $spaced = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', '_', $spaced) ?? $spaced;

        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $spaced), '_'));
    }

    /**
     * @return array<int, string>
     */
    private static function tokenize(string $name): array
    {
        return array_values(array_filter(explode('_', $name), fn (string $part) => $part !== ''));
    }
}
