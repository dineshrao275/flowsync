<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DetectsPlatformUsers;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user notification preferences (a per-event email-delivery switch). Like
 * the `notifications` table, `notification_preferences` only exists in tenant
 * databases, so a platform super admin reads the catalog defaults and has no
 * row to write — mirroring the personal-notifications family. Self-scoped, so
 * no permission beyond auth.
 */
class NotificationPreferenceController extends Controller
{
    use DetectsPlatformUsers;

    public function show(Request $request): JsonResponse
    {
        if ($this->isPlatformSuperAdmin($request)) {
            return response()->json(['preferences' => $this->defaults(), 'email_mode' => NotificationPreference::MODE_INSTANT]);
        }

        $prefs = NotificationPreference::firstWhere('user_id', $request->user()->id);

        return response()->json([
            'preferences' => $prefs
                ? array_merge($this->defaults(), $prefs->preferences ?? [])
                : $this->defaults(),
            'email_mode' => NotificationPreference::emailMode($request->user()),
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        if ($this->isPlatformSuperAdmin($request)) {
            abort(404);
        }

        $prefs = NotificationPreference::firstOrNew(['user_id' => $request->user()->id]);
        $validated = $request->validated();
        $prefs->preferences = array_merge(
            $this->defaults(),
            $prefs->preferences ?? [],
            $validated['preferences'] ?? [],
        );
        if (isset($validated['email_mode'])) {
            $prefs->email_mode = $validated['email_mode'];
        }
        $prefs->save();

        return response()->json([
            'message' => __('Notification preferences updated.'),
            'preferences' => $prefs->preferences,
            'email_mode' => NotificationPreference::emailMode($request->user()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return collect(config('notifications.events', []))
            ->mapWithKeys(fn (string $event) => [$event => true])
            ->all();
    }
}
