<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentContract;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;

/**
 * Escape-aware "contains" search. A raw `%{$term}%` lets the person typing `%` or `_`
 * match everything; here those are matched literally. Works the same on
 * SQLite and PostgreSQL.
 *
 * The escape character is deliberately NOT the backslash: PDO's placeholder
 * parser treats `\'` as an escaped quote, so a second `escape '\'` clause
 * swallows the `?` between them as string content and PostgreSQL answers
 * HY093 (SQLite tolerates it, which is why the suite never caught it).
 */
final class Like
{
    /** The LIKE escape character: parser-safe (no backslash), escaped in terms as `!!`. */
    private const ESCAPE = '!';

    /** `%term%` with the term's own wildcards neutralised. */
    public static function contains(string $term): string
    {
        $e = self::ESCAPE;

        return '%'.str_replace([$e, '%', '_'], [$e.$e, $e.'%', $e.'_'], $term).'%';
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
            // PostgreSQL's LIKE is case-sensitive; ILIKE matches SQLite's behaviour and is what the pg_trgm
            // indexes (F2.5) accelerate.
            $operator = $group->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            foreach ($columns as $column) {
                $group->orWhereRaw($grammar->wrap($column)." $operator ? escape '".self::ESCAPE."'", [$pattern]);
            }
        });
    }
}
