<?php

namespace Tests\Unit;

use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Employee\SensitiveFieldRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The masking primitives on their own.
 *
 * Unit tests rather than feature tests on purpose: every rule here is about
 * *this* value, and the interesting cases are the degenerate ones — a null, an
 * empty string, a free-text field that is not an address, a number too short to
 * mask. Driving those through HTTP to reach a one-line function would be the
 * kind of test that stops being run.
 */
class SensitiveFieldRedactorTest extends TestCase
{
    private SensitiveFieldRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new SensitiveFieldRedactor;
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function emails(): array
    {
        return [
            'ordinary address' => ['ananya.sharma@flowsync.test', 'a***@***'],
            'single character local part' => ['a@flowsync.test', 'a***@***'],
            // The domain is masked too: for a *personal* address the domain is
            // the provider, and in a company where four people use the same one
            // it narrows the field to a handful of candidates.
            'plus addressing' => ['ananya+hr@gmail.com', 'a***@***'],
            'no at sign' => ['not an address', 'n***'],
            'leading at sign' => ['@flowsync.test', '@***'],
            'null' => [null, null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('emails')]
    public function test_it_masks_an_email(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->redactor->email($value));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function phones(): array
    {
        return [
            'international' => ['+15550001111', '*********11'],
            'formatted' => ['+1 (555) 000-1111', '*********11'],
            'local' => ['5550001111', '********11'],
            // The extension is not part of the number being masked.
            'extension' => ['+15550001111 ext 4', '*********11'],
            'extension with a hash' => ['+15550001111 #4', '*********11'],
            'extension with an x' => ['+15550001111 x4', '*********11'],
            // Too short to keep two digits from without revealing the whole
            // thing, so nothing is kept.
            'one digit' => ['7', '***'],
            'two digits' => ['77', '***'],
            'null' => [null, null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('phones')]
    public function test_it_masks_a_phone(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->redactor->phone($value));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function names(): array
    {
        return [
            'full name' => ['Ravi Kumar', 'R***'],
            'one character' => ['R', 'R***'],
            'null' => [null, null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('names')]
    public function test_it_masks_a_name(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->redactor->name($value));
    }

    public function test_a_date_of_birth_is_dropped_whole(): void
    {
        // No partial mask exists for a date: a year, or a month and a year, is
        // an identifier on its own in any company small enough to have a
        // directory. The method takes no value precisely so no caller can pass
        // one and expect a mask back.
        $this->assertNull($this->redactor->dateOfBirth());
    }

    public function test_the_masked_payload_keeps_every_key(): void
    {
        $masked = $this->redactor->masked($this->employee([
            'personal_email' => 'private@flowsync.test',
            'phone' => '+15550001111',
        ]));

        $this->assertTrue($masked['restricted']);
        $this->assertSame('p***@***', $masked['personal_email']);
        $this->assertSame('*********11', $masked['phone']);
        $this->assertNull($masked['date_of_birth']);
        $this->assertNull($masked['notes']);
        $this->assertSame(
            ['line1', 'line2', 'city', 'state', 'postal_code', 'country'],
            array_keys($masked['address']),
            'A fixed address shape, so one client layout serves both payloads.',
        );
        $this->assertSame(['name', 'phone', 'relation'], array_keys($masked['emergency_contact']));
    }

    public function test_exposed_columns_lists_only_what_is_actually_set(): void
    {
        $exposed = $this->redactor->exposedColumns($this->employee([
            'personal_email' => 'private@flowsync.test',
            'phone' => '+15550001111',
            'notes' => 'Something.',
        ]));

        $this->assertSame(['personal_email', 'phone', 'notes'], $exposed);
    }

    public function test_exposed_columns_treats_an_empty_string_as_unset(): void
    {
        // A column saved as "" is not a value, and logging it as exposed would
        // claim the reader saw something.
        $exposed = $this->redactor->exposedColumns($this->employee([
            'personal_email' => '',
            'phone' => '+15550001111',
        ]));

        $this->assertSame(['phone'], $exposed);
    }

    public function test_exposed_columns_is_empty_for_a_bare_record(): void
    {
        $this->assertSame([], $this->redactor->exposedColumns($this->employee()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(array $attributes = []): Employee
    {
        $employee = new Employee;

        foreach ($attributes as $column => $value) {
            $employee->{$column} = $value;
        }

        return $employee;
    }
}
