<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeatureModuleToggleRequest;
use App\Models\SubscriptionPlan;
use App\Services\ModuleTree;
use App\Services\PlatformAudit;
use Illuminate\Http\JsonResponse;

/**
 * Module × plan feature grid. Reads the canonical module catalog from
 * config/subscriptions.php and toggles a module per plan (persisted into
 * `plans.limits.modules`). Drives the Phase 15 module gates.
 */
class FeatureManagementController extends Controller
{
    public function __construct(private readonly ModuleTree $moduleTree) {}

    public function index(): JsonResponse
    {
        return response()->json([
            // Grouped for display; the client never regroups the flat list
            // itself, so labels and grouping stay owned by the backend.
            'groups' => $this->moduleTree->groups(),
            'plans' => SubscriptionPlan::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (SubscriptionPlan $plan) => [
                    'id' => $plan->id,
                    'slug' => $plan->slug,
                    'name' => $plan->name,
                    'is_active' => $plan->is_active,
                    'modules' => array_values(array_intersect(
                        config('subscriptions.modules', []),
                        $plan->limit('modules') ?? []
                    )),
                ]),
        ]);
    }

    public function update(FeatureModuleToggleRequest $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $data = $request->validated();

        $wasEnabled = in_array($data['module'], $subscriptionPlan->limit('modules') ?? [], true);

        $modules = collect($subscriptionPlan->limit('modules') ?? [])
            ->reject(fn (string $m) => $m === $data['module'])
            ->when($data['enabled'], fn ($c) => $c->push($data['module']))
            ->values()
            ->all();

        $limits = $subscriptionPlan->limits ?? [];
        $limits['modules'] = array_values(array_unique(array_intersect(config('subscriptions.modules', []), $modules)));

        $subscriptionPlan->update(['limits' => $limits]);

        app(PlatformAudit::class)->diff(
            $request,
            'plan.module_toggled',
            'subscription_plans',
            $subscriptionPlan->id,
            ['enabled' => $wasEnabled],
            ['enabled' => $data['enabled']],
            ['module' => $data['module'], 'enabled' => $data['enabled']],
        );

        return response()->json([
            'plan' => ['id' => $subscriptionPlan->id, 'slug' => $subscriptionPlan->slug, 'modules' => $limits['modules']],
            'message' => $data['enabled'] ? 'Module enabled.' : 'Module disabled.',
        ]);
    }
}
