<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemSettingsUpdateRequest;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

class SystemSettingsController extends Controller
{
    private const KEYS = ['app_name', 'public_registration', 'default_plan_id', 'maintenance_mode'];

    public function index(): JsonResponse
    {
        return response()->json(['settings' => $this->all()]);
    }

    public function update(SystemSettingsUpdateRequest $request): JsonResponse
    {
        $data = $request->validated();

        // The exists: rule resolves against the default connection; here SA
        // requests run on the system connection anyway, but stay explicit.
        if ($data['default_plan_id'] ?? null) {
            $plan = SubscriptionPlan::find($data['default_plan_id']);
            if (! $plan) {
                abort(422, 'default_plan_id');
            }
            if (! $plan->is_active) {
                abort(422, 'That plan is not active, choose another.');
            }
        }

        $before = $this->all();

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            PlatformSetting::set(
                $key,
                $key === 'app_name' || $key === 'default_plan_id' ? $data[$key] : (bool) $data[$key]
            );
        }

        $after = $this->all();
        $changed = collect(self::KEYS)->filter(fn ($k) => ($before[$k] ?? null) !== ($after[$k] ?? null))->values()->all();

        if ($changed) {
            AuditLog::create([
                'subject_type' => 'platform_settings',
                'subject_id' => null,
                'action' => 'platform.settings_updated',
                'data' => ['keys' => $changed],
                'actor_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json(['settings' => $after, 'message' => 'Settings saved.']);
    }

    private function all(): array
    {
        return [
            'app_name' => (string) PlatformSetting::value('app_name', 'FlowSync'),
            'public_registration' => PlatformSetting::bool('public_registration', false),
            'default_plan_id' => PlatformSetting::value('default_plan_id') !== null
                ? (int) PlatformSetting::value('default_plan_id')
                : null,
            'maintenance_mode' => PlatformSetting::bool('maintenance_mode', false),
        ];
    }
}
