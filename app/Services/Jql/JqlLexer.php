<?php

declare(strict_types=1);

namespace App\Services\Jql;

/**
 * Splits a query string into tokens. Types: `str` (quoted), `word`, `kw` (AND OR NOT IN IS EMPTY
 * NULL ORDER BY ASC DESC, upper-cased), `op`, `(`, `)`, `,`. Unquoted words may contain letters,
 * digits and `_ . - + : / @`; anything else must be quoted, so a stray character is an error, not a
 * silently different query.
 */
final class JqlLexer
{
    private const KEYWORDS = ['AND', 'OR', 'NOT', 'IN', 'IS', 'EMPTY', 'NULL', 'ORDER', 'BY', 'ASC', 'DESC'];

    private const MAX_TOKENS = 400;

    /** @return list<array{0: string, 1: string, 2: int}> [type, value, position] */
    public static function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $c = $source[$i];

            if (ctype_space($c)) {
                $i++;

                continue;
            }

            if (count($tokens) >= self::MAX_TOKENS) {
                throw new JqlException(__('The query is too long.'));
            }

            if ($c === '"' || $c === "'") {
                [$value, $i] = self::quoted($source, $i);
                $tokens[] = ['str', $value, $i];

                continue;
            }

            if ($c === '(' || $c === ')' || $c === ',') {
                $tokens[] = [$c, $c, $i];
                $i++;

                continue;
            }

            $two = substr($source, $i, 2);
            if (in_array($two, ['!=', '>=', '<=', '!~'], true)) {
                $tokens[] = ['op', $two, $i];
                $i += 2;

                continue;
            }

            if (in_array($c, ['=', '~', '>', '<'], true)) {
                $tokens[] = ['op', $c, $i];
                $i++;

                continue;
            }

            if (preg_match('/\G[\p{L}\p{N}_.\-+:\/@]+/u', $source, $m, 0, $i) === 1) {
                $word = $m[0];
                $upper = strtoupper($word);
                $tokens[] = in_array($upper, self::KEYWORDS, true) ? ['kw', $upper, $i] : ['word', $word, $i];
                $i += strlen($word);

                continue;
            }

            throw new JqlException(__('Unexpected character ":char" at position :pos. Put text values in quotes.', ['char' => $c, 'pos' => $i + 1]));
        }

        return $tokens;
    }

    /** @return array{0: string, 1: int} the string and the index after the closing quote */
    private static function quoted(string $source, int $start): array
    {
        $quote = $source[$start];
        $out = '';
        $length = strlen($source);

        for ($i = $start + 1; $i < $length; $i++) {
            $c = $source[$i];

            if ($c === '\\' && $i + 1 < $length) {
                $out .= $source[++$i];

                continue;
            }

            if ($c === $quote) {
                return [$out, $i + 1];
            }

            $out .= $c;
        }

        throw new JqlException(__('Unterminated quote starting at position :pos.', ['pos' => $start + 1]));
    }
}
