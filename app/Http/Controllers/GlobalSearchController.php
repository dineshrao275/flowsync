<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TenantDatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    use ScopesVisibleTasks;

    public function __invoke(Request $request, TenantDatabaseManager $dbm): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $q = trim($validated['q']);
        $user = $request->user();

        // A non-impersonating super admin searches across all provisioned
        // tenants; everyone else is scoped to the tenant DB of the current session.
        $isSuperAdmin = $user->is_super_admin && ! $request->session()->has('impersonate');

        $workspaces = [];
        $projects = [];
        $tasks = [];
        $users = [];

        if ($isSuperAdmin) {
            $tenants = Tenant::query()->where('status', 'active')->orWhere('status', 'trial')->get()
                ->filter(fn (Tenant $tenant) => $tenant->isProvisioned());

            foreach ($tenants as $tenant) {
                $dbm->using($tenant, function () use ($q, $user, $tenant, &$workspaces, &$projects, &$tasks, &$users): void {
                    $workspaces = array_merge($workspaces, $this->searchWorkspaces($q, $user, $tenant->name));
                    $projects = array_merge($projects, $this->searchProjects($q, $user, $tenant->name));
                    $tasks = array_merge($tasks, $this->searchTasks($q, $user));
                    $users = array_merge($users, $this->searchUsers($q, $user, $tenant->name));
                });
            }
        } else {
            $workspaces = $this->searchWorkspaces($q, $user, $this->currentTenantName($request));
            $projects = $this->searchProjects($q, $user, $this->currentTenantName($request));
            $tasks = $this->searchTasks($q, $user);
            $users = $this->searchUsers($q, $user, $this->currentTenantName($request));
        }

        $results = [
            'workspaces' => array_slice($workspaces, 0, 5),
            'projects' => array_slice($projects, 0, 5),
            'tasks' => array_slice($tasks, 0, 8),
            'users' => array_slice($users, 0, 5),
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
        $tenantId = $request->session()->get('impersonate.tenant_id')
            ?? $request->session()->get('login.tenant_id');

        if (! $tenantId) {
            return null;
        }

        return Tenant::whereKey($tenantId)->value('name');
    }

    private function managesAllResources(User $user): bool
    {
        return ($user->is_super_admin && ! request()->session()->has('impersonate'))
            || $user->hasPermission('workspaces.manage');
    }
}