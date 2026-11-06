<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Psr\Http\Message\StreamInterface;

/** Reads a server-sent-events body incrementally and yields each decoded `data:` JSON payload. */
final class SseReader
{
    /** @return \Generator<int, array<string,mixed>> */
    public static function events(StreamInterface $body): \Generator
    {
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                $event = self::decode($line);
                if ($event === false) {
                    return;
                }
                if ($event !== null) {
                    yield $event;
                }
            }
        }

        $event = self::decode(rtrim($buffer, "\r\n"));
        if (is_array($event)) {
            yield $event;
        }
    }

    /** @return array<string,mixed>|null|false null = not a data line, false = end-of-stream sentinel */
    private static function decode(string $line): array|null|false
    {
        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $payload = trim(substr($line, 5));
        if ($payload === '[DONE]') {
            return false;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
