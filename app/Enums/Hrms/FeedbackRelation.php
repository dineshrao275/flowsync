<?php

namespace App\Enums\Hrms;

enum FeedbackRelation: string
{
    case Manager = 'manager';
    case Peer = 'peer';
    case DirectReport = 'direct_report';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Manager',
            self::Peer => 'Peer',
            self::DirectReport => 'Direct report',
        };
    }
}
