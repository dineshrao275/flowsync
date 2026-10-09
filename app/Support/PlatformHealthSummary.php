<?php

namespace App\Support;

/**
 * Platform health summary helper — pure function, no framework/tenancy dependency.
 */
class PlatformHealthSummary
{
    /**
     * Return a health ping with status and timestamp.
     *
     * @return array{status: string, timestamp: string}
     */
    public static function ping(): array
    {
        return [
            'status' => 'healthy',
            'timestamp' => (new \DateTimeImmutable)->format(\DATE_ATOM),
        ];
    }
}
