<?php

namespace App\Services\Hrms\Employee;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Employee\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee/HRMS — serving one employee's photo.
 *
 * Its own class because photo serving is a concern with its own rules, none of
 * which belong on a controller: the URL must be signed, the lookup must happen
 * on the *tenant's* database even though the request starts on the central one,
 * and every download is an access to a person's face and belongs in the data
 * access ledger. Three rules that are easy to forget individually are not easy
 * to remember at all once they are in a fourth method on an HTTP controller.
 */
class EmployeePhotoService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * A temporary signed URL for the photo, or null when there is none.
     *
     * Null rather than a URL that 404s: the avatar slot falls back to initials,
     * and a broken image is worse than a missing one.
     *
     * The `$actorUserId` is the reader the URL is minted for. The download route
     * has no session — that is the entire reason it is signed — so without this
     * the download is an unattributable read of someone's face.
     */
    public function url(Employee $employee, ?int $actorUserId = null): ?string
    {
        if ($employee->photo_path === null || $employee->photo_path === '') {
            return null;
        }

        return url()->temporarySignedRoute(
            'hrms.employees.photo',
            now()->addHours(1),
            // Both of these ride *inside* the signature, so neither can be
            // altered without invalidating it.
            [
                'employee' => $employee->id,
                // The *central* tenant id: the employee id is tenant-local, so on
                // the central connection that the signed route runs on it
                // resolves to nothing at all.
                'tenant' => app(TenantContext::class)->currentId(),
                'actor' => $actorUserId,
            ],
        );
    }

    /**
     * Stream the photo for a signed request.
     *
     * Every argument is a primitive on purpose: this runs with no session and no
     * tenant context, and taking a `Request` here would invite a future caller
     * to read something from it that only the signed query parameters may
     * contain.
     */
    public function stream(int $employeeId, int $tenantId, ?int $actorUserId, ?string $ipAddress): StreamedResponse
    {
        $tenant = Tenant::find($tenantId);

        abort_if($tenant === null, 404);

        return app(TenantDatabaseManager::class)->using($tenant, function () use ($employeeId, $actorUserId, $ipAddress) {
            $record = Employee::query()->where('id', $employeeId)->first();

            abort_if($record === null || $record->photo_path === null, 404);

            if (! Storage::disk('public')->exists($record->photo_path)) {
                abort(404, 'File no longer exists.');
            }

            $this->audit->accessed(
                (new Employee)->getMorphClass(),
                $record->id,
                DataAccessAction::Download,
                ['photo_path'],
                $this->resolveActor($actorUserId),
                $ipAddress,
            );

            return Storage::disk('public')->download($record->photo_path);
        });
    }

    /**
     * The user who minted the URL, if the account still exists.
     *
     * Resolved *inside* the tenant connection: `users` is a tenant table and
     * does not exist on the central connection this request starts on. Null is
     * possible even though the URL is only ever minted for an authenticated
     * reader — the account can be deleted inside the hour the link lives — and
     * the log should say "unknown" rather than invent one.
     */
    private function resolveActor(?int $actorUserId): ?User
    {
        return $actorUserId === null ? null : User::find($actorUserId);
    }
}
