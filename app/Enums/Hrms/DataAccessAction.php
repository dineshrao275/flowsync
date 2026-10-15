<?php

namespace App\Enums\Hrms;

enum DataAccessAction: string
{
    case View = 'view';
    case Download = 'download';
    case Export = 'export';

    /**
     * Anything that pulls a bulk slice of sensitive data outward. View and
     * download are single-record reads; export is the one that needs the
     * stricter approval and logging policy.
     */
    public function isBulk(): bool
    {
        return $this === self::Export;
    }
}
