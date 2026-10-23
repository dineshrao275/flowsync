<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Layering ratchets (docs/coding-standards.md section 2).
 *
 * Controllers do not touch `DB::` and services do not `abort()` (they throw domain
 * exceptions / ValidationException). Existing offenders are frozen at their current
 * occurrence count: lower the number as code moves out, delete the entry at zero. A new
 * file, or a higher count, fails.
 */
class ArchitectureRatchetTest extends TestCase
{
    /** @var array<string, int> */
    private const CONTROLLER_DB = [
        'app/Http/Controllers/AnalyticsController.php' => 1,
        'app/Http/Controllers/CalendarFeedController.php' => 5,
        'app/Http/Controllers/Concerns/ScopesVisibleTasks.php' => 1,
        'app/Http/Controllers/Hrms/Performance/ReviewSummaryController.php' => 1,
        'app/Http/Controllers/Hrms/Talent/CareerController.php' => 14,
        'app/Http/Controllers/Hrms/Talent/SkillExpectationController.php' => 6,
        'app/Http/Controllers/MetricsController.php' => 1,
        'app/Http/Controllers/PlatformHealthController.php' => 3,
        'app/Http/Controllers/PlatformRoleController.php' => 2,
        'app/Http/Controllers/SearchController.php' => 1,
        'app/Http/Controllers/SystemAnalyticsController.php' => 3,
        'app/Http/Controllers/SystemQueueController.php' => 2,
        'app/Http/Controllers/TenantController.php' => 2,
        'app/Http/Controllers/UnifiedAuditController.php' => 2,
        'app/Http/Controllers/UserController.php' => 1,
        'app/Http/Controllers/UserImportController.php' => 1,
        'app/Http/Controllers/WorkflowController.php' => 1,
    ];

    /** @var array<string, int> */
    private const SERVICE_ABORT = [
        'app/Services/Hrms/Approval/ChainTemplates.php' => 3,
        'app/Services/Hrms/Document/DocumentDownload.php' => 5,
        'app/Services/Hrms/Document/DocumentLifecycle.php' => 1,
        'app/Services/Hrms/Employee/EmployeePhotoService.php' => 5,
        'app/Services/Hrms/Payroll/PayslipDownload.php' => 5,
        'app/Services/SubscriptionService.php' => 3,
        'app/Services/Time/TimesheetService.php' => 1,
    ];

    /** @var array<string, int> */
    private const CONTROLLER_INLINE_VALIDATION = [
        'app/Http/Controllers/AuditLogsController.php' => 2,
        'app/Http/Controllers/AutomationRuleController.php' => 1,
        'app/Http/Controllers/BillingController.php' => 4,
        'app/Http/Controllers/BlogPostController.php' => 1,
        'app/Http/Controllers/BoardPreferenceController.php' => 1,
        'app/Http/Controllers/ClientLogController.php' => 1,
        'app/Http/Controllers/CommentController.php' => 2,
        'app/Http/Controllers/Concerns/HandlesCustomFields.php' => 1,
        'app/Http/Controllers/Concerns/ManagesListSavedFilters.php' => 2,
        'app/Http/Controllers/Concerns/NormalizesBooleanInput.php' => 1,
        'app/Http/Controllers/Concerns/ResolvesDateRange.php' => 1,
        'app/Http/Controllers/ContactController.php' => 1,
        'app/Http/Controllers/CustomFieldController.php' => 2,
        'app/Http/Controllers/ExportController.php' => 1,
        'app/Http/Controllers/FeatureFlagController.php' => 3,
        'app/Http/Controllers/GithubIntegrationController.php' => 1,
        'app/Http/Controllers/Hrms/Asset/AssetController.php' => 1,
        'app/Http/Controllers/Hrms/Attendance/AttendanceRecordsController.php' => 3,
        'app/Http/Controllers/Hrms/Attendance/RegularizationController.php' => 1,
        'app/Http/Controllers/Hrms/CompOff/CompOffCreditController.php' => 1,
        'app/Http/Controllers/Hrms/CompOff/CompOffRequestController.php' => 1,
        'app/Http/Controllers/Hrms/CompOff/CompOffSettingsController.php' => 1,
        'app/Http/Controllers/Hrms/Document/LetterController.php' => 2,
        'app/Http/Controllers/Hrms/Document/SignatureController.php' => 3,
        'app/Http/Controllers/Hrms/DocumentController.php' => 4,
        'app/Http/Controllers/Hrms/Employee/EmployeeBulkController.php' => 2,
        'app/Http/Controllers/Hrms/Employee/EmployeeChangeController.php' => 3,
        'app/Http/Controllers/Hrms/EmployeeController.php' => 1,
        'app/Http/Controllers/Hrms/Expense/ExpenseClaimController.php' => 1,
        'app/Http/Controllers/Hrms/Holiday/HolidayAssignmentController.php' => 1,
        'app/Http/Controllers/Hrms/Holiday/HolidayOptionalController.php' => 1,
        'app/Http/Controllers/Hrms/HrAutomationRuleController.php' => 1,
        'app/Http/Controllers/Hrms/HrmsAnalyticsController.php' => 1,
        'app/Http/Controllers/Hrms/InboxController.php' => 2,
        'app/Http/Controllers/Hrms/Leave/LeaveAdjustmentController.php' => 1,
        'app/Http/Controllers/Hrms/Leave/LeaveBalanceController.php' => 1,
        'app/Http/Controllers/Hrms/Leave/LeaveExemptionController.php' => 1,
        'app/Http/Controllers/Hrms/Leave/LeaveRequestController.php' => 4,
        'app/Http/Controllers/Hrms/Lifecycle/DocumentRequestController.php' => 1,
        'app/Http/Controllers/Hrms/Lifecycle/ExitInterviewController.php' => 2,
        'app/Http/Controllers/Hrms/Lifecycle/OffboardingCaseController.php' => 1,
        'app/Http/Controllers/Hrms/Lifecycle/OnboardingCaseController.php' => 1,
        'app/Http/Controllers/Hrms/Lifecycle/ResignationController.php' => 3,
        'app/Http/Controllers/Hrms/MyTeamController.php' => 2,
        'app/Http/Controllers/Hrms/Payroll/EmployeeLoanController.php' => 3,
        'app/Http/Controllers/Hrms/Payroll/EmployeeSalaryController.php' => 1,
        'app/Http/Controllers/Hrms/Payroll/PayrollBankFileController.php' => 1,
        'app/Http/Controllers/Hrms/Payroll/PayrollRunController.php' => 1,
        'app/Http/Controllers/Hrms/Payroll/SalaryStructureController.php' => 1,
        'app/Http/Controllers/Hrms/Payroll/StatutoryDeclarationController.php' => 1,
        'app/Http/Controllers/Hrms/Payroll/TdsProjectController.php' => 2,
        'app/Http/Controllers/Hrms/Performance/DevelopmentPlanController.php' => 3,
        'app/Http/Controllers/Hrms/Performance/ImprovementPlanController.php' => 4,
        'app/Http/Controllers/Hrms/Performance/OkrController.php' => 2,
        'app/Http/Controllers/Hrms/Performance/PerformanceGoalController.php' => 1,
        'app/Http/Controllers/Hrms/RecordCommentController.php' => 1,
        'app/Http/Controllers/Hrms/Shift/RosterController.php' => 1,
        'app/Http/Controllers/Hrms/Shift/ShiftSwapController.php' => 3,
        'app/Http/Controllers/Hrms/Talent/CareerController.php' => 3,
        'app/Http/Controllers/Hrms/Talent/SkillController.php' => 4,
        'app/Http/Controllers/Hrms/Talent/SkillExpectationController.php' => 1,
        'app/Http/Controllers/Hrms/TaskLinkController.php' => 1,
        'app/Http/Controllers/IssueTypeController.php' => 2,
        'app/Http/Controllers/LabelController.php' => 2,
        'app/Http/Controllers/LeadController.php' => 2,
        'app/Http/Controllers/MediaController.php' => 2,
        'app/Http/Controllers/MySubscriptionController.php' => 2,
        'app/Http/Controllers/NotificationPolicyController.php' => 1,
        'app/Http/Controllers/OnboardingController.php' => 2,
        'app/Http/Controllers/PlanController.php' => 1,
        'app/Http/Controllers/PlatformRoleController.php' => 1,
        'app/Http/Controllers/ProjectComponentController.php' => 2,
        'app/Http/Controllers/ProjectController.php' => 2,
        'app/Http/Controllers/ProjectDashboardController.php' => 1,
        'app/Http/Controllers/ProjectIssueSchemeController.php' => 1,
        'app/Http/Controllers/ProjectMemberController.php' => 3,
        'app/Http/Controllers/ProjectRoleController.php' => 2,
        'app/Http/Controllers/ProjectTemplateController.php' => 2,
        'app/Http/Controllers/ProjectVersionController.php' => 2,
        'app/Http/Controllers/RegisterCardController.php' => 1,
        'app/Http/Controllers/ReportBuilderController.php' => 1,
        'app/Http/Controllers/RoleController.php' => 3,
        'app/Http/Controllers/RoleTemplateController.php' => 1,
        'app/Http/Controllers/SavedFilterController.php' => 1,
        'app/Http/Controllers/SlaPolicyController.php' => 1,
        'app/Http/Controllers/SprintController.php' => 3,
        'app/Http/Controllers/SsoSettingsController.php' => 1,
        'app/Http/Controllers/SupportAccessController.php' => 2,
        'app/Http/Controllers/SupportTicketController.php' => 2,
        'app/Http/Controllers/SystemInvoiceController.php' => 1,
        'app/Http/Controllers/SystemSupportController.php' => 3,
        'app/Http/Controllers/TaskController.php' => 3,
        'app/Http/Controllers/TaskMoveController.php' => 1,
        'app/Http/Controllers/TaskMoveRequestController.php' => 2,
        'app/Http/Controllers/TaskWatcherController.php' => 1,
        'app/Http/Controllers/TenantController.php' => 4,
        'app/Http/Controllers/TenantIntakeController.php' => 3,
        'app/Http/Controllers/TenantSubscriptionController.php' => 6,
        'app/Http/Controllers/TimerController.php' => 1,
        'app/Http/Controllers/TimesheetController.php' => 4,
        'app/Http/Controllers/TwoFactorController.php' => 5,
        'app/Http/Controllers/UnifiedAuditController.php' => 1,
        'app/Http/Controllers/UserAccessController.php' => 1,
        'app/Http/Controllers/UserController.php' => 3,
        'app/Http/Controllers/UserImportController.php' => 3,
        'app/Http/Controllers/WebhookEndpointController.php' => 1,
        'app/Http/Controllers/WebsiteRedirectController.php' => 1,
        'app/Http/Controllers/WorkLogController.php' => 2,
        'app/Http/Controllers/WorkflowController.php' => 1,
        'app/Http/Controllers/WorkloadController.php' => 1,
        'app/Http/Controllers/WorkspaceController.php' => 2,
        'app/Http/Controllers/WorkspaceMemberController.php' => 2,
    ];

    public function test_controllers_do_not_gain_direct_db_calls(): void
    {
        $this->assertRatchet('app/Http/Controllers', '/\bDB::/', self::CONTROLLER_DB, 'DB:: in controllers');
    }

    public function test_controllers_do_not_gain_inline_validation(): void
    {
        $this->assertRatchet('app/Http/Controllers', '/->validate\(/', self::CONTROLLER_INLINE_VALIDATION, 'inline validate() in controllers (use a FormRequest)');
    }

    public function test_services_do_not_gain_abort_calls(): void
    {
        $this->assertRatchet('app/Services', '/\babort(_if|_unless)?\(/', self::SERVICE_ABORT, 'abort() in services');
    }

    /** @param array<string, int> $frozen */
    private function assertRatchet(string $dir, string $pattern, array $frozen, string $what): void
    {
        $root = dirname(__DIR__, 2);
        $problems = [];
        $seen = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $dir.substr($file->getPathname(), strlen("{$root}/{$dir}"));
            $count = preg_match_all($pattern, file_get_contents($file->getPathname()));
            $limit = $frozen[$path] ?? 0;
            $seen[$path] = true;

            if ($count > $limit) {
                $problems[] = "{$path}: {$count} ({$what}, limit {$limit})";
            }
        }

        foreach ($frozen as $path => $limit) {
            if (! isset($seen[$path])) {
                $problems[] = "{$path}: listed but missing, remove it";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
