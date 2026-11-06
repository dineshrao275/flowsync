<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Hrms\Leave\LeaveBalanceReading;

/** The caller's OWN leave balances (self-service: no permission needed to read your own record, as in the HR hub). */
final class MyLeaveBalanceTool extends AbstractTool
{
    public function __construct(private readonly LeaveBalanceReading $reading) {}

    public function name(): string
    {
        return 'my_leave_balance';
    }

    public function description(): string
    {
        return 'Get the current user\'s own leave balances by leave type for the current leave year.';
    }

    public function parameters(): array
    {
        return $this->schema([]);
    }

    public function modules(): array
    {
        return ['hrms.core', 'hrms.leave'];
    }

    public function product(): ?string
    {
        return 'hrms';
    }

    public function available(User $user): bool
    {
        return $user->employee !== null;
    }

    public function execute(User $user, array $args): ToolResult
    {
        $balances = $this->reading->balancesFor($user->employee)->map(fn ($b) => [
            'type' => $b->type?->name,
            'year' => $b->year,
            'available' => (float) $b->balance,
            'used' => (float) $b->availed,
            'accrued' => (float) $b->accrued,
        ])->values();

        return new ToolResult(['balances' => $balances->all()], [[
            'type' => 'link',
            'title' => 'My leave',
            'subtitle' => $balances->map(fn ($b) => "{$b['type']}: {$b['available']}")->take(4)->implode(' · '),
            'badges' => [],
            'link' => AiLinks::hrms('leave/mine'),
        ]]);
    }
}
