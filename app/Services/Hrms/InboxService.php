<?php

declare(strict_types=1);

namespace App\Services\Hrms;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Inbox\InboxRead;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Support\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Inbox/HRMS — one queue from every pending thing, no queue table.
 *
 * Each source contributes items shaped `{key, type, title, subtitle,
 * priority, due_at, meta, source_id}`; the server sends `meta` (ids the
 * deep link needs), never a URL — the client builds `href`. Reads persist
 * per (login, key) in `inbox_reads`; anything unread is whatever the live
 * sources return minus those keys, counted under a 30s cache that every
 * mark call busts.
 *
 * Two deliberate extensions of the plan's sketch, both documented where
 * they happen: onboarding and offboarding asks ride distinct key prefixes
 * (one autoincrement shared by two tables would mark both read at once),
 * and undated pending items are included (no deadline is not no duty).
 * A non-impersonating super admin reads an empty queue — platform work
 * lives elsewhere, matching the notifications short-circuit.
 */
class InboxService
{
    public function __construct(
        private readonly ReportingLine $reporting,
        private readonly InboxSources $sources,
        private readonly TenantContext $context,
    ) {}

    /**
     * The merged queue, high priority first, then earliest due (dateless
     * last), then key for stability.
     */
    public function items(User $user, int $page = 1, int $perPage = 20, int $dueWithinDays = 14): LengthAwarePaginator
    {
        $all = $this->all($user, $dueWithinDays)
            ->sortBy(fn (array $item): string => sprintf(
                '%d|%s|%s',
                $item['priority'] === 'high' ? 0 : 1,
                $item['due_at'] ?? '9999-12-31',
                $item['key'],
            ))
            ->values();

        return new Paginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
        );
    }

    public function unreadCount(User $user): int
    {
        return (int) Cache::remember(
            "hrms.inbox.unread.{$user->id}",
            30,
            fn (): int => $this->unreadKeys($user)->count(),
        );
    }

    /**
     * @param  list<string>  $keys
     */
    public function markRead(User $user, array $keys): int
    {
        // Only live keys persist: a junk row today could hide a real item
        // reusing the string tomorrow, so unknown keys are declined rather
        // than stored.
        $live = $this->all($user)->pluck('key')->flip();

        $marked = 0;

        foreach (array_unique(array_filter(array_map('strval', $keys))) as $key) {
            if (! isset($live[mb_substr($key, 0, 120)])) {
                continue;
            }

            InboxRead::query()->updateOrCreate(
                ['user_id' => $user->id, 'item_key' => mb_substr($key, 0, 120)],
                ['read_at' => now()],
            );
            $marked++;
        }

        Cache::forget("hrms.inbox.unread.{$user->id}");

        return $marked;
    }

    public function markAllRead(User $user): int
    {
        return $this->markRead($user, $this->unreadKeys($user)->all());
    }

    /**
     * @return Collection<int, string>
     */
    private function unreadKeys(User $user): Collection
    {
        $read = InboxRead::query()->where('user_id', $user->id)->pluck('item_key');

        return $this->all($user)->pluck('key')->diff($read)->values();
    }

    /**
     * Every source, unpaginated and unsorted: approvals waiting on the
     * login, case items owned by them, their reports, or their HR pool,
     * their own corrections and file asks, their unsigned hardware, their
     * reports' expiring files, and their own disputed payslips.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function all(User $user, int $dueWithinDays = 14): Collection
    {
        if ($user->is_super_admin && ! $this->context->hasTenant()) {
            return collect();
        }

        $employeeId = Employee::where('user_id', $user->id)->value('id');
        $reportIds = $employeeId === null
            ? []
            : array_map(fn (Employee $report): int => $report->id, $this->reporting->reportsOf(Employee::find($employeeId)));

        return $this->sources->collect($user, $employeeId, $reportIds, $dueWithinDays)
            ->unique('key')
            ->values();
    }
}
