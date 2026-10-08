<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Http\Requests\GlobalSearchRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\TenantLimits;
use App\Support\TenantDatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    use ScopesVisibleTasks;

    public function __invoke(GlobalSearchRequest $request, TenantDatabaseManager $dbm): JsonResponse
    {
        $q = trim($request->validated('q'));
        $user = $request->user();

        // A non-impersonating super admin searches across all provisioned
        // tenants; everyone else is scoped to the tenant DB of the current session.
        $isSuperAdmin = $user->is_super_admin && ! $request->session()->has('impersonate');

        $workspaces = [];
        $projects = [];
        $tasks = [];
        $users = [];
        $employees = [];

        if ($isSuperAdmin) {
            $tenants = Tenant::query()->where('status', 'active')->orWhere('status', 'trial')->get()
                ->filter(fn (Tenant $tenant) => $tenant->isProvisioned());

            foreach ($tenants as $tenant) {
                $dbm->using($tenant, function () use ($q, $user, $tenant, &$workspaces, &$projects, &$tasks, &$users, &$employees): void {
                    $workspaces = array_merge($workspaces, $this->searchWorkspaces($q, $user, $tenant->name));
                    $projects = array_merge($projects, $this->searchProjects($q, $user, $tenant->name));
                    $tasks = array_merge($tasks, $this->searchTasks($q, $user));
                    $users = array_merge($users, $this->searchUsers($q, $user, $tenant->name));

                    if ($this->maySeeEmployees($user, $tenant)) {
                        $employees = array_merge($employees, $this->searchEmployees($q, $tenant->name));
                    }
                });
            }
        } else {
            $workspaces = $this->searchWorkspaces($q, $user, $this->currentTenantName($request));
            $projects = $this->searchProjects($q, $user, $this->currentTenantName($request));
            $tasks = $this->searchTasks($q, $user);
            $users = $this->searchUsers($q, $user, $this->currentTenantName($request));

            $tenant = $this->currentTenant($request);

            if ($tenant !== null && $this->maySeeEmployees($user, $tenant)) {
                $employees = $this->searchEmployees($q, $tenant->name);
            }
        }

        $results = [
            'workspaces' => array_slice($workspaces, 0, 5),
            'projects' => array_slice($projects, 0, 5),
            'tasks' => array_slice($tasks, 0, 8),
            'users' => array_slice($users, 0, 5),
            'employees' => array_slice($employees, 0, 5),
        ];

        return response()->json([
            'query' => $q,
            'results' => $results,
            'total' => array_sum(array_map('count', $results)),
        ]);
    }

    private function searchWorkspaces(string $q, User $user, ?string $tenantName = null): array
    {
        $query = Workspace::query()
            ->where('name', 'like', "%{$q}%")
            ->withCount('projects');

        if (! $this->managesAllResources($user)) {
            $query->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
        }

        return $query->orderBy('name')->limit(5)->get()->map(fn (Workspace $workspace) => [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'tenant' => $tenantName,
            'projects_count' => $workspace->projects_count,
        ])->values()->all();
    }

    private function searchProjects(string $q, User $user, ?string $tenantName = null): array
    {
        $query = Project::query()
            ->with(['workspace'])
            ->withCount('tasks')
            ->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$q}%")
                ->orWhere('key', 'like', "%{$q}%"));

        if (! $this->managesAllResources($user)) {
            $query->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
        }

        return $query->orderBy('name')->limit(5)->get()->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'key' => $project->key,
            'workspace' => $project->workspace?->name,
            'tenant' => $tenantName,
            'tasks_count' => $project->tasks_count,
        ])->values()->all();
    }

    private function searchTasks(string $q, User $user): array
    {
        return $this->visibleTaskQuery($user)
            ->where(fn (Builder $builder) => $builder
                ->where('tasks.title', 'like', "%{$q}%")
                ->orWhere('tasks.key', 'like', "%{$q}%")
                ->orWhere('tasks.description', 'like', "%{$q}%"))
            ->orderByDesc('tasks.updated_at')
            ->limit(8)
            ->get()
            ->map(fn ($task) => $this->presentTask($task))
            ->values()
            ->all();
    }

    private function searchUsers(string $q, User $user, ?string $tenantName = null): array
    {
        // People search is only enabled for super admins and users holding
        // `users.view`; everyone else simply gets no user results.
        if (! $this->managesAllResources($user) && ! $user->hasPermission('users.view')) {
            return [];
        }

        return User::query()
            ->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(5)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant' => $tenantName,
            ])
            ->values()
            ->all();
    }

    private function currentTenantName(Request $request): ?string
    {
        return $this->currentTenant($request)?->name;
    }

    private function currentTenant(Request $request): ?Tenant
    {
        $tenantId = $request->session()->get('impersonate.tenant_id')
            ?? $request->session()->get('login.tenant_id');

        if (! $tenantId) {
            return null;
        }

        return Tenant::whereKey($tenantId)->first();
    }

    /**
     * People-directory search: the caller names the directory permission
     * and the tenant holds the core module. Names and codes travel, never
     * contact details — the palette links to the profile, it does not
     * preview the person.
     */
    private function maySeeEmployees(User $user, Tenant $tenant): bool
    {
        // The platform super admin reads everything, but `hasPermission`
        // would query the tenant `roles` table on the system connection —
        // so the bypass comes first, like `managesAllResources` does.
        if (! ($user->is_super_admin && ! request()->session()->has('impersonate'))
            && ! $user->hasPermission('hrms.employees.view')) {
            return false;
        }

        return app(TenantLimits::class)->hasModule($tenant, 'hrms.core');
    }

    private function searchEmployees(string $q, ?string $tenantName = null): array
    {
        return Employee::query()
            ->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$q}%")
                ->orWhere('employee_code', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(5)
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->name,
                'tenant' => $tenantName,
            ])
            ->values()
            ->all();
    }

    private function managesAllResources(User $user): bool
    {
        return ($user->is_super_admin && ! request()->session()->has('impersonate'))
            || $user->hasPermission('workspaces.manage');
    }
}
