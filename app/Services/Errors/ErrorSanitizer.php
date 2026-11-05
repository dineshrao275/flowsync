<?php

declare(strict_types=1);

namespace App\Services\Errors;

use App\Support\AuditMask;

/**
 * Makes an error safe to keep: field-name masking (the shared AuditMask rules),
 * redaction of credentials/emails that slipped into a message, removal of the
 * deployment's absolute paths, and hard size caps.
 *
 * Never store the raw request body: the caller decides whether input travels at
 * all, and `request_input` is always masked here as a second line of defence.
 */
class ErrorSanitizer
{
    public function message(string $message): string
    {
        $clean = $this->redact($message);

        return mb_substr(trim($clean), 0, (int) config('error_logging.max_message_chars', 4000));
    }

    public function stack(?string $stack): ?string
    {
        if ($stack === null || $stack === '') {
            return null;
        }

        $clean = $this->redact(str_replace(base_path().DIRECTORY_SEPARATOR, '', $stack));

        return mb_substr($clean, 0, (int) config('error_logging.max_stack_chars', 20000));
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public function context(array $context): array
    {
        unset($context['exception'], $context['_token'], $context['error_capture']);

        $masked = AuditMask::mask($context) ?? [];

        $clean = $this->walk($masked);

        // JSON columns have a practical size ceiling; a huge context is less
        // useful than the first slice of it.
        $encoded = json_encode($clean);

        if ($encoded === false) {
            return [];
        }

        if (mb_strlen($encoded) > (int) config('error_logging.max_context_chars', 16000)) {
            return ['truncated' => true, 'preview' => mb_substr($encoded, 0, (int) config('error_logging.max_context_chars', 16000))];
        }

        return $clean;
    }

    /** @param  array<array-key, mixed>  $value */
    private function walk(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->walk($item);
            } elseif (is_string($item)) {
                $value[$key] = mb_substr($this->redact($item), 0, 4000);
            } elseif (is_object($item)) {
                $value[$key] = mb_substr($this->redact($item::class), 0, 255);
            }
        }

        return $value;
    }

    private function redact(string $value): string
    {
        // Credentials that commonly appear inside exception messages or URLs.
        $value = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ***', $value) ?? $value;
        $value = preg_replace('/((?:password|passwd|secret|token|api[_-]?key|access[_-]?key|authorization)\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1***', $value) ?? $value;
        $value = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '***@***', $value) ?? $value;

        return $value;
    }
}
