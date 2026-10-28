<?php

declare(strict_types=1);

namespace App\Services\Jql;

/**
 * Recursive-descent parser:
 *
 *   query  := [or] [ORDER BY field [ASC|DESC] {, field [ASC|DESC]}]
 *   or     := and {OR and}
 *   and    := unary {AND unary}
 *   unary  := NOT unary | '(' or ')' | cond
 *   cond   := field op value | field [NOT] IN '(' value {, value} ')' | field IS [NOT] (EMPTY|NULL)
 *   value  := string | word | word '(' ')'          e.g. currentUser(), today()
 *
 * Output is a plain array AST the compiler walks; nothing here touches the database.
 */
final class JqlParser
{
    private const MAX_DEPTH = 12;

    private const MAX_IN_VALUES = 50;

    /** @var list<array{0: string, 1: string, 2: int}> */
    private array $tokens;

    private int $pos = 0;

    private int $depth = 0;

    /** @return array{where: ?array<string, mixed>, order: list<array{0: string, 1: string}>} */
    public static function parse(string $source): array
    {
        $parser = new self;
        $parser->tokens = JqlLexer::tokenize($source);

        $where = null;
        if ($parser->peekIsNot('kw', 'ORDER') && $parser->more()) {
            $where = $parser->orExpr();
        }

        $order = [];
        if ($parser->accept('kw', 'ORDER')) {
            $parser->expect('kw', 'BY');
            do {
                $field = $parser->expect('word')[1];
                $dir = $parser->accept('kw', 'DESC') ? 'desc' : ($parser->accept('kw', 'ASC') ? 'asc' : 'asc');
                $order[] = [strtolower($field), $dir];
            } while ($parser->accept(','));
        }

        if ($parser->more()) {
            throw new JqlException(__('Unexpected ":token" at position :pos.', ['token' => $parser->tokens[$parser->pos][1], 'pos' => $parser->tokens[$parser->pos][2] + 1]));
        }

        return ['where' => $where, 'order' => $order];
    }

    /** @return array<string, mixed> */
    private function orExpr(): array
    {
        $items = [$this->andExpr()];

        while ($this->accept('kw', 'OR')) {
            $items[] = $this->andExpr();
        }

        return count($items) === 1 ? $items[0] : ['type' => 'or', 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function andExpr(): array
    {
        $items = [$this->unary()];

        while ($this->accept('kw', 'AND')) {
            $items[] = $this->unary();
        }

        return count($items) === 1 ? $items[0] : ['type' => 'and', 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function unary(): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new JqlException(__('The query is nested too deeply.'));
        }

        try {
            if ($this->accept('kw', 'NOT')) {
                return ['type' => 'not', 'item' => $this->unary()];
            }

            if ($this->accept('(')) {
                $inner = $this->orExpr();
                $this->expect(')');

                return $inner;
            }

            return $this->condition();
        } finally {
            $this->depth--;
        }
    }

    /** @return array<string, mixed> */
    private function condition(): array
    {
        $field = strtolower($this->expect('word')[1]);

        if ($this->accept('kw', 'IS')) {
            $negate = $this->accept('kw', 'NOT');
            if (! $this->accept('kw', 'EMPTY') && ! $this->accept('kw', 'NULL')) {
                throw $this->unexpected(__('EMPTY or NULL'));
            }

            return ['type' => 'cond', 'field' => $field, 'op' => $negate ? 'not empty' : 'empty', 'values' => []];
        }

        $negate = $this->accept('kw', 'NOT');
        if ($this->accept('kw', 'IN')) {
            $this->expect('(');
            $values = [$this->value()];
            while ($this->accept(',')) {
                $values[] = $this->value();
                if (count($values) > self::MAX_IN_VALUES) {
                    throw new JqlException(__('Too many values in the list (maximum :max).', ['max' => self::MAX_IN_VALUES]));
                }
            }
            $this->expect(')');

            return ['type' => 'cond', 'field' => $field, 'op' => $negate ? 'not in' : 'in', 'values' => $values];
        }

        if ($negate) {
            throw $this->unexpected('IN');
        }

        $op = $this->expect('op')[1];

        return ['type' => 'cond', 'field' => $field, 'op' => $op, 'values' => [$this->value()]];
    }

    /** @return array{t: string, v: string} */
    private function value(): array
    {
        $token = $this->tokens[$this->pos] ?? null;

        if ($token === null) {
            throw new JqlException(__('The query ends where a value was expected.'));
        }

        if ($token[0] === 'str') {
            $this->pos++;

            return ['t' => 'str', 'v' => $token[1]];
        }

        if ($token[0] === 'word') {
            $this->pos++;

            if ($this->accept('(')) {
                $this->expect(')');

                return ['t' => 'fn', 'v' => strtolower($token[1])];
            }

            return ['t' => 'word', 'v' => $token[1]];
        }

        throw $this->unexpected(__('a value'));
    }

    private function more(): bool
    {
        return $this->pos < count($this->tokens);
    }

    private function peekIsNot(string $type, ?string $value = null): bool
    {
        $t = $this->tokens[$this->pos] ?? null;

        return $t === null || $t[0] !== $type || ($value !== null && $t[1] !== $value);
    }

    private function accept(string $type, ?string $value = null): bool
    {
        if ($this->peekIsNot($type, $value)) {
            return false;
        }

        $this->pos++;

        return true;
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function expect(string $type, ?string $value = null): array
    {
        if ($this->peekIsNot($type, $value)) {
            throw $this->unexpected($value ?? $type);
        }

        return $this->tokens[$this->pos++];
    }

    private function unexpected(string $wanted): JqlException
    {
        $t = $this->tokens[$this->pos] ?? null;

        return new JqlException($t === null
            ? __('The query ends where :wanted was expected.', ['wanted' => $wanted])
            : __('Expected :wanted but found ":token" at position :pos.', ['wanted' => $wanted, 'token' => $t[1], 'pos' => $t[2] + 1]));
    }
}
