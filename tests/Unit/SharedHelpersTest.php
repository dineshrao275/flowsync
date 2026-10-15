<?php

namespace Tests\Unit;

use App\Http\Controllers\Concerns\NormalizesFilters;
use App\Support\Hrms\Auditable;
use App\Support\UniqueSlug;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

enum SharedHelpersStatus: string
{
    case Open = 'open';
}

class SharedHelpersTest extends TestCase
{
    public function test_clean_drops_nulls_and_clean_blank_drops_empty_strings_too(): void
    {
        $subject = new class
        {
            use NormalizesFilters;

            public function both(array $f): array
            {
                return [$this->clean($f), $this->cleanBlank($f)];
            }
        };

        [$clean, $blank] = $subject->both(['a' => null, 'b' => '', 'c' => 0, 'd' => 'x']);

        $this->assertSame(['b' => '', 'c' => 0, 'd' => 'x'], $clean);
        $this->assertSame(['c' => 0, 'd' => 'x'], $blank);
    }

    public function test_unique_slug_appends_the_first_free_numeric_suffix(): void
    {
        $taken = ['acme', 'acme-2'];

        $this->assertSame('acme-3', UniqueSlug::make('acme', fn (string $s): bool => in_array($s, $taken, true)));
        $this->assertSame('free', UniqueSlug::make('free', fn (string $s): bool => in_array($s, $taken, true)));
    }

    public function test_auditable_snapshot_unwraps_enums_and_coerces_requested_fields(): void
    {
        $model = new class extends Model
        {
            protected $guarded = [];
        };
        $model->status = SharedHelpersStatus::Open;
        $model->total_days = '2.5';
        $model->employee_id = 7;

        $this->assertSame(
            ['employee_id' => 7, 'status' => 'open', 'total_days' => 2.5],
            Auditable::snapshot($model, ['employee_id', 'status', 'total_days'], ['total_days' => 'float']),
        );
    }
}
