<?php

namespace Tests\Unit;

use App\Services\Jql\JqlException;
use App\Services\Jql\JqlLexer;
use App\Services\Jql\JqlParser;
use Tests\TestCase;

/** F3.2 — the JQL-style parser (no database, no framework). */
class JqlParserTest extends TestCase
{
    public function test_a_simple_condition(): void
    {
        $ast = JqlParser::parse('status = "In Progress"');

        $this->assertSame(['type' => 'cond', 'field' => 'status', 'op' => '=', 'values' => [['t' => 'str', 'v' => 'In Progress']]], $ast['where']);
        $this->assertSame([], $ast['order']);
    }

    public function test_and_binds_tighter_than_or_and_parentheses_override(): void
    {
        $ast = JqlParser::parse('a = 1 OR b = 2 AND c = 3')['where'];
        $this->assertSame('or', $ast['type']);
        $this->assertSame('and', $ast['items'][1]['type']);

        $grouped = JqlParser::parse('(a = 1 OR b = 2) AND c = 3')['where'];
        $this->assertSame('and', $grouped['type']);
        $this->assertSame('or', $grouped['items'][0]['type']);
    }

    public function test_not_in_is_empty_and_functions(): void
    {
        $in = JqlParser::parse('status NOT IN ("To Do", Done)')['where'];
        $this->assertSame('not in', $in['op']);
        $this->assertCount(2, $in['values']);

        $this->assertSame('empty', JqlParser::parse('assignee IS EMPTY')['where']['op']);
        $this->assertSame('not empty', JqlParser::parse('due IS NOT NULL')['where']['op']);

        $fn = JqlParser::parse('assignee = currentUser()')['where'];
        $this->assertSame(['t' => 'fn', 'v' => 'currentuser'], $fn['values'][0]);

        $this->assertSame('not', JqlParser::parse('NOT title ~ x')['where']['type']);
    }

    public function test_order_by_alone_or_after_a_filter(): void
    {
        $this->assertSame([['created', 'desc'], ['title', 'asc']], JqlParser::parse('ORDER BY created DESC, title')['order']);

        $both = JqlParser::parse('status = Done ORDER BY updated');
        $this->assertNotNull($both['where']);
        $this->assertSame([['updated', 'asc']], $both['order']);
        $this->assertNull(JqlParser::parse('ORDER BY created')['where']);
    }

    public function test_keywords_are_case_insensitive_and_quotes_may_be_escaped(): void
    {
        $this->assertSame('and', JqlParser::parse('a = 1 and b = 2')['where']['type']);
        $this->assertSame('say "hi"', JqlParser::parse('title = "say \"hi\""')['where']['values'][0]['v']);
    }

    /** @dataProvider broken */
    public function test_malformed_queries_raise_a_readable_error(string $source): void
    {
        $this->expectException(JqlException::class);
        JqlParser::parse($source);
    }

    public static function broken(): array
    {
        return [
            'unterminated quote' => ['title = "oops'],
            'missing value' => ['status ='],
            'dangling and' => ['status = Done AND'],
            'unclosed paren' => ['(status = Done'],
            'stray character' => ['status = Done; DROP TABLE tasks'],
            'bad is' => ['status IS Done'],
            'not without in' => ['status NOT Done'],
            'trailing junk' => ['status = Done extra'],
        ];
    }

    public function test_depth_and_length_are_bounded(): void
    {
        $this->expectException(JqlException::class);
        JqlParser::parse(str_repeat('(', 40).'a = 1'.str_repeat(')', 40));
    }

    public function test_the_token_cap_stops_pathological_input(): void
    {
        $this->expectException(JqlException::class);
        JqlLexer::tokenize(str_repeat('a = 1 AND ', 200));
    }
}
