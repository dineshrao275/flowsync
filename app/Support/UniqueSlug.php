<?php

namespace App\Support;

use Closure;

/**
 * Appends -2, -3, ... to a slug until `$exists` says it is free.
 */
final class UniqueSlug
{
    /**
     * @param  Closure(string): bool  $exists
     */
    public static function make(string $base, Closure $exists): string
    {
        $candidate = $base;
        $i = 2;

        while ($exists($candidate)) {
            $candidate = $base.'-'.$i++;
        }

        return $candidate;
    }
}
