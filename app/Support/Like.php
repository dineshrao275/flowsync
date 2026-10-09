<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentContract;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;

/**
 * Escape-aware "contains" search. A raw `%{$term}%` lets the person typing `%` or `_`
 * match everything (and `\` break the pattern); here those are matched literally.
 * Works the same on SQLite and PostgreSQL (`escape '\'` is spelled out for SQLite).
 */
final class Like
{
    /** `%term%` with the term's own wildcards neutralised. */
    public static function contains(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }

    /**
     * Add one grouped `col like … or col like …` condition.
     *
     * @param  list<string>  $columns
     */
    public static function any(EloquentContract|QueryContract $query, array $columns, string $term): EloquentContract|QueryContract
    {
        $pattern = self::contains($term);

        return $query->where(function ($group) use ($columns, $pattern): void {
            $grammar = $group->getGrammar();
            foreach ($columns as $column) {
                $group->orWhereRaw($grammar->wrap($column)." like ? escape '\\'", [$pattern]);
            }
        });
    }
}
