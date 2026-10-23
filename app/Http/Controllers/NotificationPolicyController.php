<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NotificationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-wide switches for emailable events (`tenant.manage`). A disabled event
 * never emails anyone in this tenant, whatever each user prefers; in-app
 * notifications are always written.
 */
class NotificationPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['policies' => $this->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        $events = (array) config('notifications.email_events', []);

        $data = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*' => ['boolean'],
        ]);

        foreach (array_keys($data['policies']) as $event) {
            if (! in_array($event, $events, true)) {
                abort(422, __('Unknown notification event(s): :events', ['events' => $event]));
            }
        }

        foreach ($data['policies'] as $event => $enabled) {
            NotificationPolicy::query()->updateOrCreate(
                ['event' => $event, 'channel' => NotificationPolicy::CHANNEL_EMAIL],
                ['enabled' => (bool) $enabled, 'updated_by' => $request->user()->id],
            );
        }

        return response()->json(['message' => __('Notification policy updated.'), 'policies' => $this->present()]);
    }

    /** @return array<string, bool> event => email enabled */
    private function present(): array
    {
        $rows = NotificationPolicy::query()->where('channel', NotificationPolicy::CHANNEL_EMAIL)->pluck('enabled', 'event');

        return collect((array) config('notifications.email_events', []))
            ->mapWithKeys(fn (string $event) => [$event => (bool) ($rows[$event] ?? true)])
            ->all();
    }
}
