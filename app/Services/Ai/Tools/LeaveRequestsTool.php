<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Hrms\HrmsScope;
use App\Services\Hrms\Leave\LeaveRequestDirectory;

/** Leave requests in the caller's HRMS scope: their own, plus reports' for `_assigned`, everyone's for `_all`/manage. Reuses the HR list's directory so the two cannot disagree. */
final class LeaveRequestsTool extends AbstractTool
{
    private const MAX = 15;

    public function __construct(private readonly LeaveRequestDirectory $directory) {}

    public function name(): string
    {
        return 'leave_requests';
    }

    public function description(): string
    {
        return 'List leave requests the user is allowed to see (their own and, if their role allows, their team\'s), newest first. Optionally filter by status.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected', 'cancelled']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX],
        ]);
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
        return $user->employee !== null || HrmsScope::canRead($user, 'hrms.leave');
    }

    public function execute(User $user, array $args): ToolResult
    {
        $all = $this->directory->listFor($user, isset($args['status']) ? ['status' => $args['status']] : []);
        $rows = $all->take((int) ($args['limit'] ?? 10));

        return new ToolResult(
            ['total' => $all->count(), 'requests' => $rows->map(fn ($r) => $this->row($r))->values()->all()],
            $rows->map(fn ($r) => [
                'type' => 'leave',
                'title' => ($r->employee?->name ?? 'Employee').' · '.($r->type?->name ?? 'Leave'),
                'subtitle' => $r->from_date->toDateString().' → '.$r->to_date->toDateString().' · '.(float) $r->total_days.' days',
                'badges' => [$r->status->value],
                'link' => AiLinks::hrms('leave'),
            ])->values()->all(),
        );
    }

    /** @return array<string,mixed> */
    private function row(object $r): array
    {
        return [
            'employee' => $r->employee?->name,
            'type' => $r->type?->name,
            'from' => $r->from_date->toDateString(),
            'to' => $r->to_date->toDateString(),
            'days' => (float) $r->total_days,
            'status' => $r->status->value,
        ];
    }
}
