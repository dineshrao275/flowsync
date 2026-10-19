<?php

namespace Tests\Feature;

use App\Support\UploadScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** F11.8 — uploads are judged by their bytes, not their name. */
class UploadScannerTest extends TestCase
{
    private function refused(UploadedFile $file): bool
    {
        try {
            app(UploadScanner::class)->assertSafe($file);

            return false;
        } catch (ValidationException) {
            return true;
        }
    }

    public function test_an_executable_renamed_to_an_image_is_refused(): void
    {
        $this->assertTrue($this->refused(UploadedFile::fake()->createWithContent('cute.png', "MZ\x90\x00\x03\x00\x00\x00 fake pe")));
        $this->assertTrue($this->refused(UploadedFile::fake()->createWithContent('run.pdf', "#!/bin/sh\nrm -rf /\n")));
    }

    public function test_content_must_match_the_extension(): void
    {
        $this->assertTrue($this->refused(UploadedFile::fake()->createWithContent('notes.pdf', 'just plain text')));
        $this->assertFalse($this->refused(UploadedFile::fake()->createWithContent('notes.txt', 'just plain text')));
        $this->assertFalse($this->refused(UploadedFile::fake()->image('photo.png', 10, 10)));
    }

    public function test_an_svg_with_script_is_refused(): void
    {
        $this->assertTrue($this->refused(UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')));
        $this->assertFalse($this->refused(UploadedFile::fake()->createWithContent('ok.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>')));
    }

    public function test_a_configured_scanner_that_reports_infection_blocks_the_upload(): void
    {
        config(['security.upload_scan.command' => 'false {file}']); // exit 1 = infected
        $this->assertTrue($this->refused(UploadedFile::fake()->createWithContent('a.txt', 'hello')));

        config(['security.upload_scan.command' => 'true {file}']);
        $this->assertFalse($this->refused(UploadedFile::fake()->createWithContent('a.txt', 'hello')));
    }
}
