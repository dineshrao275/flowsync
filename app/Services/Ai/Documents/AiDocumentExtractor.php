<?php

declare(strict_types=1);

namespace App\Services\Ai\Documents;

use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Turns an uploaded requirements file into plain text, inside FlowSync and independent of any AI
 * provider. The file is untrusted: sizes are capped before anything is parsed (a DOCX is a zip, so its
 * uncompressed size is checked first), nothing is executed or fetched, and the result is only ever
 * passed on as quoted data.
 */
final class AiDocumentExtractor
{
    public const EXTENSIONS = ['txt', 'md', 'csv', 'json', 'docx', 'pdf'];

    private const DOCX_MAX_BYTES = 8 * 1024 * 1024;

    /** @throws DocumentExtractionException */
    public function extract(string $path, string $extension): ExtractedDocument
    {
        $extension = strtolower($extension);
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new DocumentExtractionException(__('This file type is not supported. Use TXT, MD, CSV, JSON, DOCX or PDF.'));
        }

        [$text, $pages] = match ($extension) {
            'docx' => [$this->docx($path), 1],
            'pdf' => $this->pdf($path),
            default => [$this->plain($path), 1],
        };

        return $this->finish($text, $pages);
    }

    /** Pasted text takes the same path as a file so the caps and cleaning are identical. */
    public function fromText(string $text): ExtractedDocument
    {
        return $this->finish($text, 1);
    }

    private function finish(string $text, int $pages): ExtractedDocument
    {
        if ($pages > (int) config('ai.document_max_pages')) {
            throw new DocumentExtractionException(__('This document has too many pages (:max at most).', ['max' => config('ai.document_max_pages')]));
        }

        $clean = $this->clean($text);
        if ($clean === '') {
            throw new DocumentExtractionException(__('No readable text was found in this document.'));
        }

        $max = (int) config('ai.document_max_chars');
        $truncated = mb_strlen($clean) > $max;

        return new ExtractedDocument($truncated ? mb_substr($clean, 0, $max) : $clean, $pages, $truncated);
    }

    private function plain(string $path): string
    {
        $raw = (string) file_get_contents($path, false, null, 0, (int) config('ai.document_max_chars') * 4 + 4);

        return mb_check_encoding($raw, 'UTF-8') ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new DocumentExtractionException(__('This DOCX file could not be read.'));
        }

        try {
            $stat = $zip->statName('word/document.xml');
            if ($stat === false) {
                throw new DocumentExtractionException(__('This DOCX file could not be read.'));
            }
            if ($stat['size'] > self::DOCX_MAX_BYTES) {
                throw new DocumentExtractionException(__('This document is too large to read.'));
            }

            $xml = (string) $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        // Paragraph/line/tab ends become whitespace before the tags go, or words run together.
        $xml = preg_replace(['/<\/w:p>/', '/<w:br\s*\/?>/', '/<w:tab\s*\/?>/'], ["\n", "\n", ' '], $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @return array{0:string,1:int} */
    private function pdf(string $path): array
    {
        try {
            $document = (new Parser)->parseFile($path);
            $pages = count($document->getPages());
            if ($pages > (int) config('ai.document_max_pages')) {
                return ['', $pages];
            }

            return [$document->getText(), max(1, $pages)];
        } catch (Throwable) {
            throw new DocumentExtractionException(__('This PDF could not be read. It may be scanned, encrypted or damaged.'));
        }
    }

    /** Strips control characters, collapses blank runs, and removes anything that could close our own data delimiter. */
    private function clean(string $text): string
    {
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
