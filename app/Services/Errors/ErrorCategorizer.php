<?php

declare(strict_types=1);

namespace App\Services\Errors;

/**
 * Best-effort module for an error, so the platform team can filter by product
 * area without reading every stack trace. Namespace wins over route: a job that
 * touches HRMS is an HRMS error even when it was queued from a generic route.
 */
class ErrorCategorizer
{
    /** @var array<string, string> */
    private const NAMESPACE_PREFIXES = [
        'App\\Services\\Hrms\\' => 'hrms',
        'App\\Http\\Controllers\\Hrms\\' => 'hrms',
        'App\\Models\\Hrms\\' => 'hrms',
        'App\\Enums\\Hrms\\' => 'hrms',
        'App\\Services\\Tasks\\' => 'tms',
        'App\\Services\\ProjectService' => 'tms',
        'App\\Services\\TaskService' => 'tms',
        'App\\Services\\WorkspaceService' => 'tms',
        'App\\Services\\WorkLogService' => 'tms',
        'App\\Http\\Controllers\\Task' => 'tms',
        'App\\Http\\Controllers\\Project' => 'tms',
        'App\\Http\\Controllers\\Workspace' => 'tms',
        'App\\Http\\Controllers\\Search' => 'tms',
        'App\\Http\\Controllers\\Dashboard' => 'tms',
        'App\\Billing\\' => 'billing',
        'App\\Services\\Billing\\' => 'billing',
        'App\\Services\\Subscriptions\\' => 'billing',
        'App\\Http\\Controllers\\Billing' => 'billing',
        'App\\Http\\Controllers\\AuthController' => 'auth',
        'App\\Services\\Auth\\' => 'auth',
        'App\\Services\\Security\\' => 'auth',
        'App\\Services\\Integrations\\' => 'integrations',
        'App\\Http\\Controllers\\Github' => 'integrations',
        'App\\Jobs\\' => 'queue',
        'Illuminate\\Queue\\' => 'queue',
        'Illuminate\\Mail\\' => 'mail',
        'Symfony\\Component\\Mailer\\' => 'mail',
        'App\\Mail\\' => 'mail',
        'App\\Http\\Controllers\\Blog' => 'cms',
        'App\\Http\\Controllers\\Cms' => 'cms',
        'App\\Http\\Controllers\\Media' => 'cms',
        'App\\Http\\Controllers\\PublicSite' => 'cms',
        'App\\Http\\Controllers\\Contact' => 'cms',
        'Illuminate\\Database\\' => 'database',
        'PDO' => 'database',
    ];

    /** @var list<array{0: string, 1: string}> */
    private const PATH_PREFIXES = [
        ['api/hrms', 'hrms'],
        ['hrms', 'hrms'],
        ['api/tasks', 'tms'],
        ['api/projects', 'tms'],
        ['api/workspaces', 'tms'],
        ['api/search', 'tms'],
        ['api/dashboard', 'tms'],
        ['api/billing', 'billing'],
        ['billing', 'billing'],
        ['api/auth', 'auth'],
        ['login', 'auth'],
        ['api/system', 'platform'],
        ['api/tenants', 'platform'],
        ['api/plans', 'platform'],
        ['api/integrations', 'integrations'],
        ['api/webhooks', 'integrations'],
        ['api/pages', 'cms'],
        ['api/blog', 'cms'],
        ['api/media', 'cms'],
    ];

    public function categorize(?string $class, ?string $path = null): string
    {
        if ($class !== null) {
            foreach (self::NAMESPACE_PREFIXES as $prefix => $module) {
                if (str_starts_with($class, $prefix)) {
                    return $module;
                }
            }
        }

        $path = strtolower(ltrim((string) $path, '/'));

        if ($path !== '') {
            foreach (self::PATH_PREFIXES as [$prefix, $module]) {
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    return $module;
                }
            }
        }

        return 'unknown';
    }
}
