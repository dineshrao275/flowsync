<?php

namespace App\Services\Users;

use App\Models\Role;
use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\Hrms\Employee\EmployeeBackfill;
use App\Services\TenantLimits;
use App\Support\GrantCeiling;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk user import from CSV (columns: name, email, roles, optional password).
 *
 * Every row goes through the same rules as creating a user by hand — the
 * importing admin can only hand out roles whose permissions they hold
 * (GrantCeiling), emails must be unused platform-wide, and the plan's user
 * limit applies — and each row reports its own errors so nothing is silently
 * dropped or silently granted.
 */
class UserImporter
{
    public const MAX_ROWS = 500;

    public const SAMPLE = [
        ['name', 'email', 'roles', 'password'],
        ['Jane Doe', 'jane.doe@example.com', 'editor', ''],
        ['John Roe', 'john.roe@example.com', 'editor|viewer', 'ChangeMe-2026'],
    ];

    public function __construct(
        private readonly TenantLimits $limits,
        private readonly TenantContext $tenant,
        private readonly EmployeeBackfill $backfill,
    ) {}

    /** @return array<int, array{line: int, name: string, email: string, roles: array<int, string>, password: string}> */
    public function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = $this->readRow($handle);
        $header = array_map(fn ($h) => Str::lower(trim((string) $h)), $header ?? []);

        foreach (['name', 'email', 'roles'] as $required) {
            if (! in_array($required, $header, true)) {
                throw ValidationException::withMessages(['file' => "The CSV needs a '{$required}' column. Download the sample for the expected format."]);
            }
        }

        $rows = [];
        $line = 1;
        while (($cells = $this->readRow($handle)) !== null) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // blank line
            }
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $rows[] = [
                'line' => $line,
                'name' => trim((string) ($row['name'] ?? '')),
                'email' => Str::lower(trim((string) ($row['email'] ?? ''))),
                'roles' => array_values(array_filter(array_map('trim', preg_split('/[|;]/', (string) ($row['roles'] ?? '')) ?: []))),
                'password' => (string) ($row['password'] ?? ''),
            ];
            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'A single import is limited to '.self::MAX_ROWS.' users. Split the file.']);
            }
        }
        fclose($handle);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The CSV has no user rows.']);
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>> the rows plus `errors` (empty = importable) and resolved `role_ids`
     */
    public function validate(User $actor, array $rows): array
    {
        $roles = Role::all();
        $bySlug = $roles->keyBy(fn (Role $r) => Str::lower($r->slug));
        $byName = $roles->keyBy(fn (Role $r) => Str::lower($r->name));

        $limit = $this->limit();
        $free = $limit === null ? PHP_INT_MAX : max(0, $limit - User::count());

        $seen = [];
        $accepted = 0;
        $out = [];

        foreach ($rows as $row) {
            $errors = [];

            if ($row['name'] === '' || mb_strlen($row['name']) > 255) {
                $errors[] = 'Name is required (max 255 characters).';
            }

            if (! filter_var($row['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($row['email']) > 255) {
                $errors[] = 'Email is not a valid address.';
            } elseif (isset($seen[$row['email']])) {
                $errors[] = "Email repeats line {$seen[$row['email']]} of this file.";
            } elseif ($this->emailTaken($row['email'])) {
                $errors[] = 'An account with this email already exists.';
            }
            $seen[$row['email']] ??= $row['line'];

            if ($row['password'] !== '' && (mb_strlen($row['password']) < 8 || mb_strlen($row['password']) > 72)) {
                $errors[] = 'Password must be 8–72 characters (or leave it blank to generate one).';
            }

            $roleIds = [];
            if ($row['roles'] === []) {
                $errors[] = 'At least one role is required.';
            }
            foreach ($row['roles'] as $name) {
                $role = $bySlug[Str::lower($name)] ?? $byName[Str::lower($name)] ?? null;
                $role ? $roleIds[] = $role->id : $errors[] = "Unknown role '{$name}'.";
            }

            // The privilege check: only roles the importing admin could assign by hand.
            if ($roleIds !== [] && ! array_filter($errors, fn ($e) => str_starts_with($e, 'Unknown role'))) {
                try {
                    GrantCeiling::assertCanAssignRoles($actor, collect($roleIds), collect());
                } catch (ValidationException $e) {
                    $errors[] = $e->errors()['roles'][0] ?? 'You cannot assign these roles.';
                }
            }

            if ($errors === []) {
                if ($accepted >= $free) {
                    $errors[] = "Your plan's user limit ({$limit}) would be exceeded.";
                } else {
                    $accepted++;
                }
            }

            $out[] = [...$row, 'role_ids' => array_values(array_unique($roleIds)), 'errors' => $errors];
        }

        return $out;
    }

    /**
     * Create the users for already-validated, error-free rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{id: int, name: string, email: string, roles: array<int, string>, temporary_password: string|null}>
     */
    public function import(array $rows): array
    {
        $created = [];

        DB::transaction(function () use ($rows, &$created): void {
            foreach ($rows as $row) {
                $generated = $row['password'] === '';
                $password = $generated ? Str::password(14, symbols: false) : $row['password'];

                $user = User::create(['name' => $row['name'], 'email' => $row['email'], 'password' => $password]);
                $user->roles()->sync($row['role_ids']);

                TenantUserRouting::updateOrCreate(
                    ['tenant_id' => $this->tenant->currentId(), 'email' => $row['email']],
                    ['user_id' => (int) $user->id, 'name' => $user->name],
                );
                $this->backfill->linkFor($user);

                $created[] = [
                    'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                    'roles' => $user->roles()->pluck('slug')->all(),
                    // Only generated passwords are echoed back, once; a supplied one is the admin's own.
                    'temporary_password' => $generated ? $password : null,
                ];
            }
        });

        return $created;
    }

    private function limit(): ?int
    {
        $tenant = $this->tenant->currentId() ? Tenant::find($this->tenant->currentId()) : null;

        return $tenant ? $this->limits->limit($tenant, 'users') : null;
    }

    private function emailTaken(string $email): bool
    {
        return User::where('email', $email)->exists()
            || TenantUserRouting::where('email', $email)->exists()
            || SystemUser::where('email', $email)->exists();
    }

    /** @return array<int, string|null>|null */
    private function readRow($handle): ?array
    {
        $cells = fgetcsv($handle, 0, ',', '"', '');
        if ($cells === false) {
            return null;
        }
        if (isset($cells[0])) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]); // Excel's UTF-8 BOM
        }

        return $cells;
    }
}
