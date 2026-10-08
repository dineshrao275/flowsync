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

        $defaultLimit = (int) $request->input('limit', 0);
        $wsLimit = (int) $request->input('workspace_limit', $defaultLimit ?: 5);
        $projLimit = (int) $request->input('project_limit', $defaultLimit ?: 5);
        $taskLimit = (int) $request->input('task_limit', $defaultLimit ?: 8);
        $userLimit = (int) $request->input('user_limit', $defaultLimit ?: 5);
        $empLimit = (int) $request->input('employee_limit', $defaultLimit ?: 5);

        if ($isSuperAdmin) {
            $tenants = Tenant::query()->where('status', 'active')->orWhere('status', 'trial')->get()
                ->filter(fn (Tenant $tenant) => $tenant->isProvisioned());

            foreach ($tenants as $tenant) {
                $dbm->using($tenant, function () use ($q, $user, $tenant, $wsLimit, $projLimit, $taskLimit, $userLimit, $empLimit, &$workspaces, &$projects, &$tasks, &$users, &$employees): void {
                    $workspaces = array_merge($workspaces, $this->searchWorkspaces($q, $user, $tenant->name, $wsLimit));
                    $projects = array_merge($projects, $this->searchProjects($q, $user, $tenant->name, $projLimit));
                    $tasks = array_merge($tasks, $this->searchTasks($q, $user, $taskLimit));
                    $users = array_merge($users, $this->searchUsers($q, $user, $tenant->name, $userLimit));

                    if ($this->maySeeEmployees($user, $tenant)) {
                        $employees = array_merge($employees, $this->searchEmployees($q, $tenant->name, $empLimit));
                    }
                });
            }
        } else {
            $workspaces = $this->searchWorkspaces($q, $user, $this->currentTenantName($request), $wsLimit);
            $projects = $this->searchProjects($q, $user, $this->currentTenantName($request), $projLimit);
            $tasks = $this->searchTasks($q, $user, $taskLimit);
            $users = $this->searchUsers($q, $user, $this->currentTenantName($request), $userLimit);

            $tenant = $this->currentTenant($request);

            if ($tenant !== null && $this->maySeeEmployees($user, $tenant)) {
                $employees = $this->searchEmployees($q, $tenant->name, $empLimit);
            }
        }

        $results = [
            'workspaces' => array_slice($workspaces, 0, $wsLimit),
            'projects' => array_slice($projects, 0, $projLimit),
            'tasks' => array_slice($tasks, 0, $taskLimit),
            'users' => array_slice($users, 0, $userLimit),
            'employees' => array_slice($employees, 0, $empLimit),
        ];

        return response()->json([
            'query' => $q,
            'results' => $results,
            'total' => array_sum(array_map('count', $results)),
        ]);
    }

    private function searchWorkspaces(string $q, User $user, ?string $tenantName = null, int $limit = 5): array
    {
        $query = Workspace::query()
            ->where('name', 'like', "%{$q}%")
            ->withCount('projects');

        if (! $this->managesAllResources($user)) {
            $query->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
        }

        return $query->orderBy('name')->limit($limit)->get()->map(fn (Workspace $workspace) => [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'tenant' => $tenantName,
            'projects_count' => $workspace->projects_count,
        ])->values()->all();
    }

    private function searchProjects(string $q, User $user, ?string $tenantName = null, int $limit = 5): array
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

        return $query->orderBy('name')->limit($limit)->get()->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'key' => $project->key,
            'workspace' => $project->workspace?->name,
            'tenant' => $tenantName,
            'tasks_count' => $project->tasks_count,
        ])->values()->all();
    }

    private function searchTasks(string $q, User $user, int $limit = 8): array
    {
        return $this->visibleTaskQuery($user)
            ->where(fn (Builder $builder) => $builder
                ->where('tasks.title', 'like', "%{$q}%")
                ->orWhere('tasks.key', 'like', "%{$q}%")
                ->orWhere('tasks.description', 'like', "%{$q}%"))
            ->orderByDesc('tasks.updated_at')
            ->limit($limit)
            ->get()
            ->map(fn ($task) => $this->presentTask($task))
            ->values()
            ->all();
    }

    private function searchUsers(string $q, User $user, ?string $tenantName = null, int $limit = 5): array
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
            ->limit($limit)
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

    private function searchEmployees(string $q, ?string $tenantName = null, int $limit = 5): array
    {
        return Employee::query()
            ->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$q}%")
                ->orWhere('employee_code', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit($limit)
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
