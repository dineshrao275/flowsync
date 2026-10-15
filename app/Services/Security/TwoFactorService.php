<?php

namespace App\Services\Security;

use App\Models\TwoFactorCredential;
use App\Support\Totp;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * TOTP enrolment and verification for one account (P8.1). Runs on the default
 * connection, i.e. in whichever database owns the account (tenant DB or system).
 * The login choreography lives in TwoFactorLogin; who must enrol lives in
 * TwoFactorPolicy.
 */
class TwoFactorService
{
    public const RECOVERY_CODES = 8;

    public function credential(Authenticatable $user): ?TwoFactorCredential
    {
        return TwoFactorCredential::where('user_id', $user->getAuthIdentifier())->first();
    }

    public function isEnabled(Authenticatable $user): bool
    {
        try {
            return $this->credential($user)?->isConfirmed() === true;
        } catch (QueryException) {
            // A tenant DB that has not run the 2FA migration yet (before `tenants:provision`)
            // simply has nobody enrolled; sign-in must keep working.
            return false;
        }
    }

    /** @return array{secret: string, uri: string} */
    public function beginEnrollment(Authenticatable $user): array
    {
        if ($this->isEnabled($user)) {
            throw ValidationException::withMessages(['form' => 'Two-factor authentication is already enabled.']);
        }

        $secret = Totp::generateSecret();
        TwoFactorCredential::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier()],
            ['secret' => $secret, 'confirmed_at' => null, 'recovery_codes' => null, 'last_used_step' => null],
        );

        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $user->email, config('app.name', 'FlowSync'))];
    }

    /**
     * Confirms enrolment with a first valid code; returns the recovery codes
     * in plain text exactly once.
     *
     * @return list<string>
     */
    public function confirm(Authenticatable $user, string $code): array
    {
        $credential = $this->credential($user);
        if (! $credential || $credential->isConfirmed()) {
            throw ValidationException::withMessages(['form' => 'Start two-factor setup first.']);
        }

        $step = Totp::verify($credential->secret, $code);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator and try again.']);
        }

        $plain = $this->newRecoveryCodes();
        $credential->update([
            'confirmed_at' => now(),
            'last_used_step' => $step,
            'recovery_codes' => array_map($this->hash(...), $plain),
        ]);

        return $plain;
    }

    public function disable(Authenticatable $user): void
    {
        TwoFactorCredential::where('user_id', $user->getAuthIdentifier())->delete();
    }

    /**
     * Verifies a TOTP code or a one-time recovery code.
     *
     * @return 'totp'|'recovery'|null which factor matched, null when neither did
     */
    public function verify(Authenticatable $user, string $input): ?string
    {
        $credential = $this->credential($user);
        if (! $credential?->isConfirmed()) {
            return null;
        }

        $step = Totp::verify($credential->secret, $input);
        // A step already spent is a replay (shoulder-surfed or intercepted code).
        if ($step !== null && ($credential->last_used_step === null || $step > $credential->last_used_step)) {
            $credential->update(['last_used_step' => $step]);

            return 'totp';
        }

        return $this->consumeRecoveryCode($credential, $input) ? 'recovery' : null;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(Authenticatable $user): array
    {
        $credential = $this->credential($user);
        if (! $credential?->isConfirmed()) {
            throw ValidationException::withMessages(['form' => 'Two-factor authentication is not enabled.']);
        }

        $plain = $this->newRecoveryCodes();
        $credential->update(['recovery_codes' => array_map($this->hash(...), $plain)]);

        return $plain;
    }

    public function recoveryCodesRemaining(Authenticatable $user): int
    {
        return count($this->credential($user)?->recovery_codes ?? []);
    }

    private function consumeRecoveryCode(TwoFactorCredential $credential, string $input): bool
    {
        $normalized = strtoupper(preg_replace('/[\s-]+/', '', $input) ?? '');
        if (strlen($normalized) !== 10) {
            return false;
        }

        $hash = $this->hash($normalized);
        $codes = $credential->recovery_codes ?? [];
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $credential->update(['recovery_codes' => array_values($codes)]);

                return true;
            }
        }

        return false;
    }

    /** @return list<string> formatted XXXXX-XXXXX */
    private function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = substr(Totp::base32Encode(random_bytes(8)), 0, 10);
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return $codes;
    }

    /** Stored as a keyed hash of the normalised code (50 bits of entropy: a fast hash is enough). */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(str_replace('-', '', $code)), (string) config('app.key'));
    }
}
