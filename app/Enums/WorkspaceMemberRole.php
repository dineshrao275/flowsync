<?php

namespace App\Enums;

enum WorkspaceMemberRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function manages(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
