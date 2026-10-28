<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\TenantLimits;
use App\Support\GrantCeiling;
use App\Support\PermissionSelector;
use App\Support\ProductCatalog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Role template gallery: starting points an admin turns into an ordinary custom role. */
class RoleTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['templates' => collect(config('role_templates'))->map(function ($t, $key) use ($request) {
            $slugs = $this->slugsFor($t['permissions'], $request);

            return ['key' => $key, 'name' => $t['name'], 'description' => $t['description'], 'permission_count' => count($slugs), 'permissions' => $slugs];
        })->values()]);
    }

    public function apply(Request $request, string $key): JsonResponse
    {
        $template = config("role_templates.$key");
        abort_if($template === null, 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('roles', 'slug')],
        ]);

        $ids = Permission::query()->whereIn('slug', $this->slugsFor($template['permissions'], $request))->pluck('id')->all();
        GrantCeiling::assertCanGrantPermissions($request->user(), $ids);

        $slug = $data['slug'] ?? $this->freeSlug(Str::slug($data['name'] ?? $template['name']));
        $role = Role::create(['name' => $data['name'] ?? $template['name'], 'slug' => $slug]);
        $role->permissions()->sync($ids);

        return response()->json(['message' => __('Role created.'), 'role' => $role->load('permissions:id,slug,name')], 201);
    }

    /** @return list<string> */
    private function slugsFor(array $selectors, Request $request): array
    {
        $catalog = collect(config('permissions.permissions', []))->pluck('slug')->all();
        $tenant = ($id = app(TenantContext::class)->currentId()) ? Tenant::find($id) : null;
        if ($tenant) {
            $enabled = app(TenantLimits::class)->coveredProducts($tenant);
            $catalog = array_values(array_filter($catalog, fn ($s) => ProductCatalog::isAllowedFor($s, $enabled)));
        }

        // A selector naming a slug the catalogue lacks (or the plan does not cover) is simply dropped.
        $selectors = array_values(array_filter($selectors, fn ($s) => str_contains($s, '*') || in_array($s, $catalog, true)));

        return app(PermissionSelector::class)->resolve($selectors, $catalog);
    }

    private function freeSlug(string $base): string
    {
        $slug = $base;
        for ($i = 2; Role::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }
}
