<?php

namespace App\Support\Pdf;

/**
 * A tiny, dependency-free PDF 1.4 writer for text documents (invoices,
 * payslips). No PDF library is vendored, and these documents are only text,
 * rules and right-aligned amounts, so the two built-in Helvetica faces are
 * all that is needed. A4, top-left origin in points, automatic page breaks.
 *
 * Text is converted to WinAnsi (Windows-1252); a character outside it prints
 * as "?" rather than corrupting the file. Widths are estimated from the
 * Helvetica metrics for the glyph classes that matter (digits, punctuation,
 * capitals), which is exact enough to right-align money columns.
 */
class SimplePdf
{
    public const WIDTH = 595.0;

    public const HEIGHT = 842.0;

    public const MARGIN = 48.0;

    /** @var array<int, string> page content streams */
    private array $pages = [''];

    private float $y = self::MARGIN;

    public function gap(float $points): static
    {
        $this->y += $points;

        return $this;
    }

    /** Text at $x on the current line; `right` aligns the text's end to $x. Does not advance the line. */
    public function text(string $text, float $x, int $size = 10, bool $bold = false, bool $right = false): static
    {
        $this->breakIfNeeded($size);
        $text = $this->encode($text);

        if ($right) {
            $x -= $this->width($text, $size, $bold);
        }

        $baseline = self::HEIGHT - $this->y - $size;
        $this->append(sprintf("BT /%s %d Tf %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $baseline, $this->escape($text)));

        return $this;
    }

    /** Finish the current line. */
    public function newline(int $size = 10): static
    {
        $this->y += $size * 1.5;

        return $this;
    }

    /** One full line of text at the left margin. */
    public function line(string $text, int $size = 10, bool $bold = false): static
    {
        return $this->text($text, self::MARGIN, $size, $bold)->newline($size);
    }

    /**
     * A table row: each cell is [text, x, right?].
     *
     * @param  array<int, array{0: string, 1: float, 2?: bool}>  $cells
     */
    public function row(array $cells, int $size = 10, bool $bold = false): static
    {
        foreach ($cells as $cell) {
            $this->text($cell[0], $cell[1], $size, $bold, $cell[2] ?? false);
        }

        return $this->newline($size);
    }

    public function rule(): static
    {
        $this->breakIfNeeded(4);
        $y = self::HEIGHT - $this->y;
        $this->append(sprintf("0.6 w %.2f %.2f m %.2f %.2f l S\n", self::MARGIN, $y, self::WIDTH - self::MARGIN, $y));
        $this->y += 6;

        return $this;
    }

    public function output(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];

        $kids = [];
        foreach ($this->pages as $i => $content) {
            $page = 5 + $i * 2;
            $kids[] = "{$page} 0 R";
            $objects[$page] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::WIDTH,
                self::HEIGHT,
                $page + 1,
            );
            $objects[$page + 1] = '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($out);
        $size = max(array_keys($objects)) + 1;
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($n = 1; $n < $size; $n++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }

        return $out."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function breakIfNeeded(int $size): void
    {
        if ($this->y + $size > self::HEIGHT - self::MARGIN) {
            $this->pages[] = '';
            $this->y = self::MARGIN;
        }
    }

    private function append(string $operators): void
    {
        $this->pages[array_key_last($this->pages)] .= $operators;
    }

    private function encode(string $text): string
    {
        $text = strtr($text, ['−' => '-', '–' => '-', '—' => '-', '“' => '"', '”' => '"', '’' => "'"]);
        $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return preg_replace('/[\x00-\x1F]/', ' ', is_string($converted) ? $converted : '?') ?? '';
    }

    private function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private function width(string $text, int $size, bool $bold): float
    {
        $units = 0;
        foreach (str_split($text) as $char) {
            $units += match (true) {
                ctype_digit($char) => 556,
                in_array($char, ['.', ',', ' ', ':', ';', "'", '|', 'i', 'l', 'j', 't', 'f', 'I'], true) => 278,
                in_array($char, ['m', 'w', 'W', 'M'], true) => 833,
                ctype_upper($char) => 667,
                default => 556,
            };
        }

        return $units / 1000 * $size * ($bold ? 1.04 : 1.0);
    }
}
