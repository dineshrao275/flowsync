<?php

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
        'app/Billing/Gateways/StripeGateway.php' => 626,
        'app/Billing/PaymentService.php' => 584,
        'app/Console/Commands/BackupTenants.php' => 374,
        'app/Http/Controllers/AuthController.php' => 377,
        'app/Http/Controllers/Hrms/Asset/AssetController.php' => 321,
        'app/Http/Controllers/TenantController.php' => 351,
        'app/Services/CustomFields/CustomFieldService.php' => 565,
        'app/Services/ExportService.php' => 312,
        'app/Services/Hrms/Defaults/HrmsDefaultsProvisioner.php' => 407,
        'app/Services/Hrms/Employee/EmployeeService.php' => 319,
        'app/Services/Hrms/Expense/ExpenseService.php' => 439,
        'app/Services/Hrms/InboxService.php' => 397,
        'app/Services/Hrms/Lifecycle/OnboardingCases.php' => 302,
        'app/Services/Hrms/MyHrService.php' => 355,
        'app/Services/Hrms/Payroll/PayrollService.php' => 454,
        'app/Services/Hrms/PerformanceService.php' => 319,
        'app/Services/Hrms/Shared/ApprovalService.php' => 407,
        'app/Services/Hrms/Statutory/StatutoryEngine.php' => 344,
        'app/Services/Hrms/Survey/EngagementService.php' => 444,
        'app/Services/Jql/JqlCompiler.php' => 416,
        'app/Services/ProjectService.php' => 359,
        'app/Services/SubscriptionService.php' => 376,
        'app/Services/TenantLimits.php' => 381,
        'app/Support/TenantDatabaseManager.php' => 461,
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
