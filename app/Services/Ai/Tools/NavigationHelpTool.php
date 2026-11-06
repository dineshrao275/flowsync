<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantLimits;
use App\Support\TenantContext;

/** Where things live in FlowSync, limited to the screens this user can actually open. */
final class NavigationHelpTool extends AbstractTool
{
    /** label, path, tenant permission, module, product */
    private const PAGES = [
        ['Dashboard', '/dashboard', 'dashboard.view', null, 'tms'],
        ['Projects', '/projects', 'workspaces.view', null, 'tms'],
        ['Workspaces', '/workspaces', 'workspaces.view', null, 'tms'],
        ['Task search', '/search', 'workspaces.view', 'global_search', 'tms'],
        ['Reports', '/reports', 'reports.view', 'reports', 'tms'],
        ['Notifications', '/notifications', null, null, null],
        ['HR hub', '/hrms', 'hrms.view', 'hrms.core', 'hrms'],
        ['My leave', '/hrms/leave/mine', 'hrms.view', 'hrms.leave', 'hrms'],
        ['Subscription and usage', '/subscription', null, null, null],
    ];

    public function __construct(private readonly TenantContext $context, private readonly TenantLimits $limits) {}

    public function name(): string
    {
        return 'navigation_help';
    }

    public function description(): string
    {
        return 'List the main FlowSync screens the user can open, with links. Use it for "where do I find..." questions.';
    }

    public function parameters(): array
    {
        return $this->schema([]);
    }

    public function execute(User $user, array $args): ToolResult
    {
        $tenant = $this->context->currentId() === null ? null : Tenant::find($this->context->currentId());
        $pages = [];

        foreach (self::PAGES as [$label, $path, $permission, $module, $product]) {
            $ok = $tenant !== null
                && ($permission === null || $user->hasPermission($permission))
                && ($module === null || $this->limits->hasModule($tenant, $module))
                && ($product === null || $this->limits->productEnabled($tenant, $product));

            if ($ok) {
                $pages[] = ['type' => 'link', 'title' => $label, 'subtitle' => '', 'badges' => [], 'link' => $path];
            }
        }

        return new ToolResult(['screens' => array_map(fn ($p) => ['name' => $p['title'], 'path' => $p['link']], $pages)], $pages);
    }
}
