<?php

declare(strict_types=1);

namespace App\Services\Errors;

/**
 * Stable identity for "the same error", so a recurring failure rolls up into one
 * row instead of flooding the console.
 *
 * The fingerprint is class + normalised message + app-relative file + module.
 * Variables that differ per occurrence (ids, uuids, numbers, quoted paths,
 * e-mail addresses) are replaced before hashing; the line number is left out on
 * purpose so a small edit above the throw site does not split the history.
 */
class ErrorFingerprint
{
    public function make(?string $class, string $message, ?string $file = null, ?string $module = null): string
    {
        return hash('sha256', implode('|', [
            $class ?? '',
            $this->normalize($message),
            $this->relativePath($file),
            $module ?? '',
        ]));
    }

    public function normalize(string $message): string
    {
        $normalized = $message;

        // Quoted absolute paths, urls, uuids, hashes, ids and numbers are all
        // per-occurrence data, not identity.
        $normalized = preg_replace('/"[^"]{120,}"/', '"…"', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b[0-9a-f]{32,}\b/i', '<hash>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d{4,}\b/', '<n>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d+\.\d+\b/', '<n>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return mb_substr(trim($normalized), 0, 500);
    }

    public function relativePath(?string $file): string
    {
        if ($file === null || $file === '') {
            return '';
        }

        return mb_substr(str_replace(base_path().DIRECTORY_SEPARATOR, '', $file), 0, 300);
    }
}
