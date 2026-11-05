<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Integrations\Git\Smart\SmartCommitParser;
use PHPUnit\Framework\TestCase;

class SmartCommitParserTest extends TestCase
{
    private SmartCommitParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SmartCommitParser;
    }

    public function test_commands_on_one_line_run_to_the_next_command(): void
    {
        $this->assertSame([
            ['command' => 'comment', 'arg' => 'ready for QA'],
            ['command' => 'time', 'arg' => '2h'],
            ['command' => 'close', 'arg' => ''],
        ], $this->parser->parse('ABC-1 #comment ready for QA #time 2h #close'));
    }

    public function test_commands_may_sit_on_later_lines_and_prose_is_ignored(): void
    {
        $commands = $this->parser->parse("Fix the login\n\nlong explanation, issue #123 mentions it\n#transition \"In Review\"\n#assign @octo-cat");

        $this->assertSame([['command' => 'transition', 'arg' => '"In Review"'], ['command' => 'assign', 'arg' => '@octo-cat']], $commands);
    }

    public function test_an_issue_number_or_unknown_hash_is_not_a_command(): void
    {
        $this->assertSame([], $this->parser->parse('closes #12, see #closed and #timeline'));
    }

    public function test_commands_are_capped_per_commit(): void
    {
        $message = implode("\n", array_fill(0, 12, '#comment hi'));

        $this->assertCount(SmartCommitParser::MAX_COMMANDS, $this->parser->parse($message));
    }

    public function test_durations_parse_in_days_hours_minutes_and_reject_nonsense(): void
    {
        $this->assertSame(90, $this->parser->minutes('1h30m'));
        $this->assertSame(480 + 120 + 5, $this->parser->minutes('1d 2h 5m'));
        $this->assertSame(45, $this->parser->minutes('45M'));
        foreach (['', 'abc', '2 hours', '0h', '-1h', '3d 1h'] as $bad) {
            $this->assertNull($this->parser->minutes($bad), "'{$bad}' is not a usable duration");
        }
    }

    public function test_status_names_and_logins_are_cleaned(): void
    {
        $this->assertSame('In Review', $this->parser->statusName('"In Review"'));
        $this->assertSame('Done', $this->parser->statusName('Done'));
        $this->assertSame('octo', $this->parser->login('@octo'));
        $this->assertNull($this->parser->login('not a login'));
    }
}
