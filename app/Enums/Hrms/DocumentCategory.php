<?php

namespace App\Enums\Hrms;

enum DocumentCategory: string
{
    case Identity = 'identity';
    case Education = 'education';
    case Employment = 'employment';
    case Tax = 'tax';
    case Bank = 'bank';
    case Medical = 'medical';
    case Asset = 'asset';
    case Letter = 'letter';
    case Other = 'other';

    /**
     * A family is what a compliance report groups by. The individual rows are
     * the facts; without the family, “every identity document” is a text
     * search over the document titles.
     */
    public function label(): string
    {
        return match ($this) {
            self::Identity => 'Identity',
            self::Education => 'Education',
            self::Employment => 'Employment',
            self::Tax => 'Tax',
            self::Bank => 'Bank',
            self::Medical => 'Medical',
            self::Asset => 'Asset',
            self::Letter => 'Letter',
            self::Other => 'Other',
        };
    }
}
