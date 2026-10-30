<?php

declare(strict_types=1);

namespace App\Services\Export;

use BackedEnum;
use DateTimeInterface;
use UnitEnum;
use ZipArchive;

class ExportCsvWriter
{
    /**
     * Write a category CSV into the ZIP using in-memory chunking.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public function write(ZipArchive $zip, string $filename, array $headers, iterable $rows): void
    {
        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, $headers);

        foreach ($rows as $row) {
            fputcsv($buffer, array_map($this->cell(...), $row));
        }

        rewind($buffer);
        $zip->addFromString($filename, (string) stream_get_contents($buffer));
        fclose($buffer);
    }

    /** Backed enums and dates arrive as objects; fputcsv only stringifies scalars. */
    public function cell(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format('c'),
            is_array($value) => json_encode($value),
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            is_object($value) => null,
            default => $value,
        };
    }
}
