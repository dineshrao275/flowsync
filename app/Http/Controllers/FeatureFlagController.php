<?php

namespace App\Http\Controllers;

use App\Models\FeatureFlag;
use App\Models\FlagOverride;
use App\Models\Tenant;
use App\Services\FeatureFlags;
use App\Services\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Super admin console for runtime feature flags and their tenant overrides (P8.7). */
class FeatureFlagController extends Controller
{
    public function index(): JsonResponse
    {
        $flags = FeatureFlag::query()->with('overrides.tenant:id,name,slug')->orderBy('key')->get();

        return response()->json(['flags' => $flags->map(fn (FeatureFlag $f) => $this->present($f))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/', Rule::unique(FeatureFlag::class, 'key')],
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'rollout_percent' => ['sometimes', 'integer', 'between:0,100'],
        ]);

        $flag = FeatureFlag::create($data);
        $this->audit($request, 'feature_flag.created', $flag, null, $flag->only(['key', 'enabled', 'rollout_percent']));

        return response()->json(['message' => 'Flag created.', 'flag' => $this->present($flag->load('overrides.tenant:id,name,slug'))], 201);
    }

    public function update(Request $request, FeatureFlag $flag): JsonResponse
    {
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'rollout_percent' => ['sometimes', 'integer', 'between:0,100'],
        ]);

        $before = $flag->only(['description', 'enabled', 'rollout_percent']);
        $flag->update($data);
        app(FeatureFlags::class)->forget();
        $this->audit($request, 'feature_flag.updated', $flag, $before, $flag->only(['description', 'enabled', 'rollout_percent']));

        return response()->json(['message' => 'Flag updated.', 'flag' => $this->present($flag->load('overrides.tenant:id,name,slug'))]);
    }

    public function destroy(Request $request, FeatureFlag $flag): JsonResponse
    {
        $this->audit($request, 'feature_flag.deleted', $flag, $flag->only(['key', 'enabled', 'rollout_percent']), null);
        $flag->delete();
        app(FeatureFlags::class)->forget();

        return response()->json(['message' => 'Flag deleted.']);
    }

    public function setOverride(Request $request, FeatureFlag $flag, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $existing = FlagOverride::where('feature_flag_id', $flag->id)->where('tenant_id', $tenant->id)->first();
        FlagOverride::updateOrCreate(
            ['feature_flag_id' => $flag->id, 'tenant_id' => $tenant->id],
            ['enabled' => $data['enabled']],
        );
        app(FeatureFlags::class)->forget();
        $this->audit($request, 'feature_flag.override_set', $flag, ['enabled' => $existing?->enabled], ['enabled' => (bool) $data['enabled']], ['tenant_id' => $tenant->id]);

        return response()->json(['message' => 'Override saved.', 'flag' => $this->present($flag->load('overrides.tenant:id,name,slug'))]);
    }

    public function clearOverride(Request $request, FeatureFlag $flag, Tenant $tenant): JsonResponse
    {
        $existing = FlagOverride::where('feature_flag_id', $flag->id)->where('tenant_id', $tenant->id)->first();
        $existing?->delete();
        app(FeatureFlags::class)->forget();
        $this->audit($request, 'feature_flag.override_cleared', $flag, ['enabled' => $existing?->enabled], null, ['tenant_id' => $tenant->id]);

        return response()->json(['message' => 'Override removed.', 'flag' => $this->present($flag->load('overrides.tenant:id,name,slug'))]);
    }

    /** @return array<string, mixed> */
    private function present(FeatureFlag $flag): array
    {
        return [
            'id' => $flag->id,
            'key' => $flag->key,
            'description' => $flag->description,
            'enabled' => $flag->enabled,
            'rollout_percent' => $flag->rollout_percent,
            'overrides' => $flag->overrides->map(fn (FlagOverride $o) => [
                'tenant_id' => $o->tenant_id,
                'tenant' => $o->tenant?->only(['id', 'name', 'slug']),
                'enabled' => $o->enabled,
            ])->values(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    private function audit(Request $request, string $action, FeatureFlag $flag, ?array $before, ?array $after, array $context = []): void
    {
        app(PlatformAudit::class)->diff($request, $action, 'feature_flags', $flag->id, $before, $after, ['key' => $flag->key, ...$context]);
    }
}
