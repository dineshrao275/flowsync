<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Mail\TaskDigestMail;
use App\Mail\TaskNotificationMail;
use App\Models\NotificationDigestItem;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Notifications — send each person one email for everything held in their
 * digest queue (digest-mode users and throttle overflow), then mark the items
 * sent. Idempotent: sent items are never picked up again.
 */
class SendNotificationDigests extends Command
{
    use RunsPerTenant;

    protected $signature = 'notifications:send-digests
        {--tenant= : Central id of a single tenant}
        {--all : Run for every provisioned tenant}';

    protected $description = 'Email each user a digest of the notifications held for them';

    public function handle(): int
    {
        $tenants = $this->tenantsOrFail();

        if ($tenants === null) {
            return self::FAILURE;
        }

        $report = $this->forEachTenant($tenants, function (): array {
            $users = 0;
            $items = 0;

            NotificationDigestItem::query()->whereNull('sent_at')->orderBy('id')->get()->groupBy('user_id')
                ->each(function ($held, $userId) use (&$users, &$items): void {
                    $user = User::find($userId);

                    if ($user === null) {
                        NotificationDigestItem::whereIn('id', $held->pluck('id'))->update(['sent_at' => now()]);

                        return;
                    }

                    $locale = Locales::normalize($user->settings?->settings['locale'] ?? null) ?? app()->getLocale();

                    $lines = $held->map(function (NotificationDigestItem $item) use ($locale): array {
                        $data = $item->data ?? [];
                        $url = (string) ($data['_url'] ?? '');
                        unset($data['_url']);

                        return [
                            'line' => TaskNotificationMail::fromData($item->type, (string) $item->actor_name, $data, $url, $locale)->line,
                            'url' => $url,
                        ];
                    })->all();

                    Mail::to($user->email, $user->name)->queue(new TaskDigestMail($lines, $locale));

                    NotificationDigestItem::whereIn('id', $held->pluck('id'))->update(['sent_at' => now()]);
                    $users++;
                    $items += count($lines);
                });

            return [$users, $items];
        });

        $this->table(['Tenant', 'Digests', 'Items'], $report['rows']);

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
