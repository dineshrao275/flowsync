<?php

namespace Tests\Unit\Hrms;

use PHPUnit\Framework\TestCase;

/**
 * Every HRMS enum's `color()` is a hex, never a Tailwind palette name.
 *
 * **The failure is silent and total.** The clients render a colour as
 * `backgroundColor: `${color}22`` — a 2-digit alpha suffix on a 6-digit hex. A
 * palette name like `emerald` yields `emerald22`, which is not a colour at
 * all, so the browser drops the declaration: the pill keeps its text colour, loses
 * its tint, and *every* status looks identical. Nothing throws, no test fails, and
 * the bug is reported as "the status colours aren't showing" long after the
 * commit that caused it.
 *
 * `EmployeeStatus::color()` shipped that way and was only caught in P2.6 by
 * someone styling the status pill. `WorkMode`, `ApprovalStatus` and
 * `ApprovalStepStatus` carried the same values with no consumer yet — which is
 * the only reason nobody had hit it. This test exists so the fourth one does not
 * have to be found by hand.
 *
 * It reflects over the enum directory rather than listing the enums, so a new
 * enum is covered the moment it exists.
 */
class HrmsEnumColorTest extends TestCase
{
    /**
     * A pure unit test on purpose — it boots no application — so the enum
     * directory is located relative to this file rather than with `app_path()`,
     * which needs a container.
     */
    private static function enumDir(): string
    {
        return dirname(__DIR__, 3).'/app/Enums/Hrms';
    }

    public function test_every_hrms_enum_colour_is_a_hex_value(): void
    {
        $enums = glob(self::enumDir().'/*.php');
        $this->assertNotEmpty($enums, 'no HRMS enums found — is the path right?');

        $offenders = [];

        foreach ($enums as $path) {
            $enum = basename($path, '.php');
            $class = 'App\\Enums\\Hrms\\'.$enum;

            // `method_exists` first: constructing a ReflectionMethod for a
            // method that is not there throws, and several enums here
            // (DataAccessAction) deliberately have no colour at all.
            if (! method_exists($class, 'color')) {
                continue;
            }

            $method = new \ReflectionMethod($class, 'color');

            if (! $method->isPublic() || $method->isStatic()) {
                continue;
            }

            foreach ($class::cases() as $case) {
                $color = $case->color();

                if (! is_string($color) || preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
                    $offenders[] = "{$enum}::{$case->name} => ".var_export($color, true);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These colours are not hex. The client appends an alpha suffix, so a Tailwind '
                .'palette name silently produces an invalid colour and every value renders alike.',
        );
    }

    /**
     * A pure unit test, so it does not need the database trait the feature tests
     * carry — but assert the reflection actually found the enums, or a rename
     * would make this pass by inspecting nothing.
     */
    public function test_the_reflection_really_inspected_the_hrms_enums(): void
    {
        $enums = glob(self::enumDir().'/*.php');
        $inspected = 0;

        foreach ($enums as $path) {
            $class = 'App\\Enums\\Hrms\\'.basename($path, '.php');
            if (! enum_exists($class)) {
                continue;
            }
            if (method_exists($class, 'color')) {
                $inspected++;
            }
        }

        $this->assertGreaterThan(
            0,
            $inspected,
            'No HRMS enum with a color() was found, so the test above is checking nothing.',
        );
    }
}
