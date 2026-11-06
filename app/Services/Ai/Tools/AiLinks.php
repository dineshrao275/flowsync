<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

/**
 * SPA paths for result cards. They mirror resources/js/utils/deepLinks.js (basename-relative:
 * the router adds /app), and are built server-side from ids FlowSync itself just read, never from
 * anything the model wrote, so the model cannot send a user to an arbitrary place.
 */
final class AiLinks
{
    public static function task(int $projectId, string $key): string
    {
        return "/projects/{$projectId}?tab=tasks&task=".rawurlencode($key);
    }

    public static function project(int $projectId): string
    {
        return "/projects/{$projectId}";
    }

    public static function workspace(int $workspaceId): string
    {
        return "/workspaces/{$workspaceId}";
    }

    public static function hrms(string $section = ''): string
    {
        return $section === '' ? '/hrms' : "/hrms/{$section}";
    }
}
