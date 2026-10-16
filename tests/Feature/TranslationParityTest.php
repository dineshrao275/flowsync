<?php

namespace Tests\Feature;

use App\Support\Locales;
use Tests\TestCase;

/**
 * i18n L8 — the release gate for every completed locale. English is the source
 * of truth; a locale may only be `complete` when it mirrors it (files, keys,
 * placeholders) and covers every literal `__('…')` the backend can emit. These
 * are file-level checks: no database, no HTTP.
 */
class TranslationParityTest extends TestCase
{
    /** @return list<string> */
    private function translatedLocales(): array
    {
        return array_values(array_diff(array_keys(Locales::completed()), ['en']));
    }

    /** @return array<string, string> dotted key => string */
    private function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            is_array($value)
                ? $flat += $this->flatten($value, $prefix.$key.'.')
                : $flat[$prefix.$key] = (string) $value;
        }

        return $flat;
    }

    /** @return list<string> sorted `:placeholder` tokens */
    private function placeholders(string $line): array
    {
        preg_match_all('/:[A-Za-z_]+/', $line, $matches);
        $tokens = array_map('strtolower', $matches[0]);
        sort($tokens);

        return $tokens;
    }

    public function test_every_completed_locale_mirrors_the_english_group_files(): void
    {
        foreach ($this->translatedLocales() as $locale) {
            foreach (glob(base_path('lang/en/*.php')) as $english) {
                $file = basename($english);
                $path = base_path("lang/{$locale}/{$file}");

                $this->assertFileExists($path, "lang/{$locale}/{$file} is missing");

                $source = $this->flatten(require $english);
                $target = $this->flatten(require $path);

                $this->assertSame([], array_values(array_diff(array_keys($source), array_keys($target))), "{$locale}/{$file} lacks keys");

                foreach ($source as $key => $line) {
                    if ($file === 'validation.php' && str_starts_with($key, 'custom.')) {
                        continue;
                    }

                    $this->assertSame(
                        $this->placeholders($line),
                        $this->placeholders($target[$key]),
                        "{$locale}/{$file}: placeholders differ for '{$key}'",
                    );
                }
            }
        }
    }

    public function test_every_literal_backend_message_has_a_translation(): void
    {
        $literals = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/\b__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($file->getPathname()), $found);

            foreach ($found[1] as $raw) {
                $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $raw);

                // `group.key` lookups live in lang/<locale>/*.php, not the JSON file.
                if (preg_match('/^[a-z_]+(\.[A-Za-z_]+)+$/', $key) === 1) {
                    continue;
                }

                $literals[$key] = true;
            }
        }

        $this->assertNotEmpty($literals);

        foreach ($this->translatedLocales() as $locale) {
            $dictionary = json_decode(file_get_contents(base_path("lang/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

            $missing = array_values(array_diff(array_keys($literals), array_keys($dictionary)));

            $this->assertSame([], $missing, "lang/{$locale}.json lacks ".count($missing).' backend messages');

            foreach ($dictionary as $english => $translated) {
                $this->assertSame(
                    $this->placeholders($english),
                    $this->placeholders($translated),
                    "lang/{$locale}.json: placeholders differ for '{$english}'",
                );
            }
        }
    }

    public function test_a_translated_backend_message_resolves_under_its_locale(): void
    {
        app()->setLocale('es');

        $this->assertSame('Tarea creada.', __('Task created.'));
        $this->assertSame('Ese ciclo está en review, no en calibración: las evaluaciones se calibran desde la calibración.', __('That cycle is :stage, not calibration — reviews calibrate from calibration.', ['stage' => 'review']));

        app()->setLocale('en');

        $this->assertSame('Task created.', __('Task created.'));
    }
}
