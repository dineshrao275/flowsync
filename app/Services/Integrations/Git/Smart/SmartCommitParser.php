<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Smart;

/**
 * Reads smart-commit commands out of a commit message. Pure text in, plain commands out - nothing here knows
 * about tasks or permissions. Recognised: `#comment <text>`, `#time <1d 2h 30m>`, `#close`, `#transition "<status>"`,
 * `#assign @login`. Several may share a line ("KEY-1 #comment ready #time 2h"); a command runs to the next one.
 */
final class SmartCommitParser
{
    /** Commands honoured per commit; the rest are ignored so one message cannot become a bulk operation. */
    public const MAX_COMMANDS = 5;

    public const COMMENT_MAX = 2000;

    /** A working day is 8 hours, the usual agile convention. */
    private const MINUTES = ['d' => 480, 'h' => 60, 'm' => 1];

    /** One commit may not log more than this in a single `#time`. */
    public const TIME_MAX_MINUTES = 24 * 60;

    private const COMMANDS = 'comment|time|close|transition|assign';

    /** @return list<array{command: string, arg: string}> */
    public function parse(string $message): array
    {
        $commands = [];
        foreach (preg_split('/\R/', $message) ?: [] as $line) {
            // Split before every "#command" that starts a word; the text before the first one is just prose.
            $parts = preg_split('/(?:^|\s)#(?='.self::COMMANDS.'\b)/i', $line) ?: [];
            array_shift($parts);
            foreach ($parts as $part) {
                if (preg_match('/^('.self::COMMANDS.')\b\s*(.*)$/i', trim($part), $m)) {
                    $commands[] = ['command' => strtolower($m[1]), 'arg' => trim($m[2])];
                }
            }
        }

        return array_slice($commands, 0, self::MAX_COMMANDS);
    }

    /** "1d 2h 30m", "90m", "1h30m" -> minutes; null when it is not a duration or exceeds the cap. */
    public function minutes(string $text): ?int
    {
        $text = strtolower(trim($text));
        if ($text === '' || ! preg_match('/^(\d+\s*[dhm]\s*)+$/', $text)) {
            return null;
        }
        preg_match_all('/(\d+)\s*([dhm])/', $text, $m, PREG_SET_ORDER);
        $total = array_sum(array_map(fn (array $p) => (int) $p[1] * self::MINUTES[$p[2]], $m));

        return $total > 0 && $total <= self::TIME_MAX_MINUTES ? $total : null;
    }

    /** `#transition "In Review"` / `#transition In Review` -> the status name. */
    public function statusName(string $arg): string
    {
        return trim($arg, " \t\"'");
    }

    /** `#assign @octo` -> octo; null if it is not a login. */
    public function login(string $arg): ?string
    {
        return preg_match('/^@?([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))$/', trim($arg), $m) ? $m[1] : null;
    }
}
