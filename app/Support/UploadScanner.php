<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

/**
 * F11.8 — content checks on a file BEFORE it is stored. The extension and the client-sent MIME type are claims;
 * this looks at the bytes: an "image" that is really a Windows/Linux executable, a PDF that is a script, an SVG
 * carrying script all stop here. An optional antivirus command (`security.upload_scan.command`, `{file}` is
 * replaced by the path; exit 0 = clean, 1 = infected) runs afterwards when configured.
 */
class UploadScanner
{
    /** Content types that are never acceptable as an upload, whatever the extension says. */
    private const FORBIDDEN = [
        'application/x-dosexec', 'application/x-msdownload', 'application/x-executable', 'application/x-sharedlib',
        'application/x-mach-binary', 'application/x-elf', 'text/x-shellscript', 'application/x-sh', 'application/java-archive',
        'application/x-httpd-php', 'text/x-php', 'application/x-bat',
    ];

    /** extension => content-type prefixes its bytes may sniff as. */
    private const FAMILIES = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'docx' => ['application/zip', 'application/vnd.openxmlformats', 'application/octet-stream'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats', 'application/octet-stream'],
        'pptx' => ['application/zip', 'application/vnd.openxmlformats', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip', 'application/octet-stream'],
        'txt' => ['text/'], 'md' => ['text/'], 'csv' => ['text/', 'application/csv'], 'json' => ['application/json', 'text/'],
    ];

    /** @throws ValidationException */
    public function assertSafe(UploadedFile $file): void
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path)) {
            throw ValidationException::withMessages(['file' => __('The upload could not be read.')]);
        }

        $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (in_array($sniffed, self::FORBIDDEN, true) || $this->looksExecutable($path)) {
            throw ValidationException::withMessages(['file' => __('That file looks like a program, which cannot be uploaded.')]);
        }

        if (isset(self::FAMILIES[$extension]) && ! $this->matches($sniffed, self::FAMILIES[$extension])) {
            throw ValidationException::withMessages(['file' => __('The file content does not match its :ext extension.', ['ext' => $extension])]);
        }

        if ($extension === 'svg' && preg_match('/<script|\son[a-z]+\s*=|javascript:/i', (string) file_get_contents($path, false, null, 0, 1_000_000))) {
            throw ValidationException::withMessages(['file' => __('That SVG contains active content and cannot be uploaded.')]);
        }

        $this->antivirus($path);
    }

    /** @param list<string> $prefixes */
    private function matches(string $sniffed, array $prefixes): bool
    {
        // Empty or opaque bytes make no claim about the type, so there is nothing to contradict the extension.
        if (in_array($sniffed, ['application/x-empty', 'application/octet-stream', ''], true)) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($sniffed, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Magic bytes of PE ("MZ"), ELF and Mach-O, plus a shebang line — in case finfo calls them something harmless. */
    private function looksExecutable(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);

        return str_starts_with($head, 'MZ') || str_starts_with($head, "\x7fELF") || str_starts_with($head, '#!')
            || in_array(bin2hex($head), ['cafebabe', 'feedface', 'feedfacf', 'cefaedfe', 'cffaedfe'], true);
    }

    private function antivirus(string $path): void
    {
        $command = config('security.upload_scan.command');
        if (! $command) {
            return;
        }

        try {
            $result = Process::timeout((int) config('security.upload_scan.timeout', 20))->run(str_replace('{file}', escapeshellarg($path), $command));
        } catch (\Throwable $e) {
            Log::warning('upload_scan.unavailable', ['error' => $e->getMessage()]);
            $result = null;
        }

        if ($result === null || ! in_array($result->exitCode(), [0, 1], true)) {
            if (config('security.upload_scan.fail_closed', false)) {
                throw ValidationException::withMessages(['file' => __('Uploads are paused while the virus scanner is unavailable.')]);
            }

            return;
        }

        if ($result->exitCode() === 1) {
            Log::warning('upload_scan.infected', ['output' => mb_substr($result->output(), 0, 300)]);

            throw ValidationException::withMessages(['file' => __('That file was rejected by the virus scanner.')]);
        }
    }
}
