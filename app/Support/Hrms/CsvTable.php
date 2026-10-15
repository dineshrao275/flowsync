<?php

namespace App\Support\Hrms;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HRMS — a small, strict CSV reader shared by the bulk importers.
 *
 * Header names are lower-cased and trimmed; every data row becomes an
 * associative array keyed by header plus its 1-based file `line` (so a report
 * can point at the row), blank lines are skipped, and the row cap is enforced
 * while reading so a huge upload is refused before it is held in memory.
 */
final class CsvTable
{
    /**
     * @param  array<int, string>  $required  header columns the file must carry
     * @return array<int, array<string, mixed>> rows incl. `line`
     *
     * @throws ValidationException on a missing column, an empty file or too many rows
     */
    public static function read(UploadedFile $file, array $required, int $maxRows): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $first = fgetcsv($handle, 0, ',', '"', '');
        $header = array_map(fn ($h) => Str::lower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $first ?: []);

        foreach ($required as $column) {
            if (! in_array($column, $header, true)) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "The CSV needs a '{$column}' column. Download the sample for the expected format."]);
            }
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }

            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $rows[] = ['line' => $line] + array_map(fn ($v) => trim((string) $v), $row);

            if (count($rows) > $maxRows) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "A single import is limited to {$maxRows} rows. Split the file."]);
            }
        }
        fclose($handle);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The CSV has no data rows.']);
        }

        return $rows;
    }

    /** @param  array<int, array<int, string>>  $rows */
    public static function render(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return (string) $csv;
    }
}
