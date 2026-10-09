<?php

namespace Tests\Unit;

use App\Contracts\Notifications\NotificationFamily;
use App\Contracts\Notifications\NotificationSender;
use App\Services\Notifications\Families\AssetNotifications;
use App\Services\Notifications\Families\ExpenseNotifications;
use App\Services\Notifications\Families\LifecycleNotifications;
use App\Services\Notifications\Families\PerformanceNotifications;
use App\Services\Notifications\Families\TaskNotifications;
use App\Services\Notifications\Families\TimeOffNotifications;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\NotificationService;
use Tests\TestCase;

/**
 * P1.6: NotificationService is a facade over per-family classes; the sender
 * contract resolves to the dispatcher and every family reports a unique key.
 */
class NotificationFamiliesTest extends TestCase
{
    public function test_the_sender_contract_resolves_to_the_dispatcher(): void
    {
        $this->assertInstanceOf(NotificationDispatcher::class, app(NotificationSender::class));
    }

    public function test_every_family_has_a_unique_key(): void
    {
        $families = [
            TaskNotifications::class, TimeOffNotifications::class, LifecycleNotifications::class,
            ExpenseNotifications::class, PerformanceNotifications::class, AssetNotifications::class,
        ];

        $keys = array_map(function (string $class): string {
            $family = app($class);
            $this->assertInstanceOf(NotificationFamily::class, $family);

            return $family->family();
        }, $families);

        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_the_facade_keeps_every_legacy_method(): void
    {
        foreach (['notify', 'forUser', 'unreadCount', 'markAllRead', 'mentionUsers', 'taskAssigned', 'taskStatusChanged',
            'taskCommented', 'taskUnblocked', 'workLogAdded', 'leaveRequested', 'leaveDecided', 'compOffRequested',
            'compOffDecided', 'onboardingTaskDue', 'payrollPublished', 'expenseSubmitted', 'expenseDecided',
            'expensePaid', 'performanceCycleCompleted', 'performanceReviewAcknowledged', 'assetAssigned',
            'assetAcknowledged', 'assetReturned', 'assetReturnOverdue', 'documentDecided',
            'offboardingClearancePending', 'performanceCycleOpened', 'performanceReviewShared'] as $method) {
            $this->assertTrue(method_exists(NotificationService::class, $method), $method);
        }
    }
}
