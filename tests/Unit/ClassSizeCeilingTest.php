<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ratchet for the 300-line class ceiling (docs/coding-standards.md section 3).
 *
 * Files already over the ceiling are frozen at their current length: they may shrink
 * (lower the number, or delete the entry once under 300) but never grow. Any other
 * file must stay at or under {@see self::CEILING} lines. Extract a collaborator by
 * responsibility rather than trimming comments.
 */
class ClassSizeCeilingTest extends TestCase
{
    private const CEILING = 300;

    /** @var array<string, int> path => frozen maximum line count */
    private const FROZEN = [
        'app/Billing/Gateways/StripeGateway.php' => 499,
        'app/Billing/PaymentService.php' => 494,
        'app/Console/Commands/BackupTenants.php' => 376,
        'app/Http/Controllers/Hrms/Asset/AssetController.php' => 323,
        'app/Http/Controllers/Hrms/EmployeeController.php' => 302,
        'app/Services/CustomFields/CustomFieldService.php' => 429,
        'app/Services/ExportService.php' => 314,
        'app/Services/Hrms/Employee/EmployeeService.php' => 321,
        'app/Services/Hrms/Expense/ExpenseService.php' => 318,
        'app/Services/Hrms/Leave/LeaveRequestDecisions.php' => 302,
        'app/Services/Hrms/Lifecycle/OnboardingCases.php' => 304,
        'app/Services/Hrms/MyHrService.php' => 357,
        'app/Services/Hrms/Payroll/PayrollService.php' => 456,
        'app/Services/Hrms/PerformanceService.php' => 321,
        'app/Services/Hrms/Shared/ApprovalService.php' => 409,
        'app/Services/Hrms/Statutory/StatutoryEngine.php' => 346,
        'app/Services/Hrms/Survey/EngagementService.php' => 358,
        'app/Services/Jql/JqlCompiler.php' => 418,
        'app/Services/ProjectService.php' => 361,
        'app/Services/SubscriptionService.php' => 378,
        'app/Services/TenantLimits.php' => 383,
        'app/Support/TenantDatabaseManager.php' => 463,
        'routes/web.php' => 451,
    ];

    public function test_no_php_file_grows_past_the_ceiling(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach (['app', 'routes'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $dir.substr($file->getPathname(), strlen("{$root}/{$dir}"));
                $lines = count(file($file->getPathname()));
                $limit = self::FROZEN[$path] ?? self::CEILING;

                if ($lines > $limit) {
                    $violations[] = "{$path}: {$lines} lines (limit {$limit})";
                }
            }
        }

        $this->assertSame([], $violations, "Files over their size limit:\n".implode("\n", $violations));
    }

    public function test_frozen_entries_are_still_needed(): void
    {
        $root = dirname(__DIR__, 2);
        $stale = [];

        foreach (self::FROZEN as $path => $max) {
            $file = "{$root}/{$path}";
            if (! is_file($file) || count(file($file)) <= self::CEILING) {
                $stale[] = $path;
            }
        }

        $this->assertSame([], $stale, "Remove from FROZEN (now under the ceiling or deleted):\n".implode("\n", $stale));
    }
}
