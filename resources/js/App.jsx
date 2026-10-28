import { BrowserRouter, Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { lazy, Suspense, useEffect } from 'react';
import { AuthProvider, useAuth } from './context/AuthContext';
import { ThemeProvider } from './context/ThemeContext';
import { ToastProvider } from './context/ToastContext';
import { NotificationProvider } from './context/NotificationContext';
import { ConfirmProvider } from './components/ui/ConfirmDialog';
import GuestRoute from './components/GuestRoute';
import ProtectedRoute from './components/ProtectedRoute';
import AdminLayout from './components/AdminLayout';
import AppSkeleton from './components/ui/AppSkeleton';

const Login = lazy(() => import('./pages/auth/Login'));
const Register = lazy(() => import('./pages/auth/Register'));
const RegisterComplete = lazy(() => import('./pages/auth/RegisterComplete'));
const ForgotPassword = lazy(() => import('./pages/auth/ForgotPassword'));
const ResetPassword = lazy(() => import('./pages/auth/ResetPassword'));
const Onboarding = lazy(() => import('./pages/Onboarding'));
const Dashboard = lazy(() => import('./pages/Dashboard'));
const Workspaces = lazy(() => import('./pages/Workspaces'));
const WorkspaceDetail = lazy(() => import('./pages/WorkspaceDetail'));
const ProjectDetail = lazy(() => import('./pages/ProjectDetail'));
const Projects = lazy(() => import('./pages/Projects'));
const Support = lazy(() => import('./pages/Support'));
const Webhooks = lazy(() => import('./pages/Webhooks'));
const AuditTimeline = lazy(() => import('./pages/AuditTimeline'));
const CustomFields = lazy(() => import('./pages/CustomFields'));
const AdminSupport = lazy(() => import('./pages/AdminSupport'));
const Leads = lazy(() => import('./pages/Leads'));
const Users = lazy(() => import('./pages/Users'));
const UserEdit = lazy(() => import('./pages/UserEdit'));
const UserImport = lazy(() => import('./pages/UserImport'));
const Roles = lazy(() => import('./pages/Roles'));
const Settings = lazy(() => import('./pages/Settings'));
const Reports = lazy(() => import('./pages/Reports'));
const ReportBuilder = lazy(() => import('./pages/ReportBuilder'));
const Timesheets = lazy(() => import('./pages/Timesheets'));
import HrmsErrorBoundary from './components/hrms/HrmsErrorBoundary';
const ApprovalChains = lazy(() => import('./pages/hrms/ApprovalChains'));
const HrmsOverview = lazy(() => import('./pages/hrms/HrmsOverview'));
const Employees = lazy(() => import('./pages/hrms/Employees'));
const EmployeeDetail = lazy(() => import('./pages/hrms/EmployeeDetail'));
const Org = lazy(() => import('./pages/hrms/Org'));
const Documents = lazy(() => import('./pages/hrms/Documents'));
const MyDocuments = lazy(() => import('./pages/hrms/MyDocuments'));
const Inbox = lazy(() => import('./pages/hrms/Inbox'));
const OnboardingCases = lazy(() => import('./pages/hrms/OnboardingCases'));
const OnboardingCaseDetail = lazy(() => import('./pages/hrms/OnboardingCaseDetail'));
const OffboardingCases = lazy(() => import('./pages/hrms/OffboardingCases'));
const Resignation = lazy(() => import('./pages/hrms/Resignation'));
const ExitInterview = lazy(() => import('./pages/hrms/ExitInterview'));
const HrAutomation = lazy(() => import('./pages/hrms/HrAutomation'));
const EmployeeChanges = lazy(() => import('./pages/hrms/EmployeeChanges'));
const ShiftSwaps = lazy(() => import('./pages/hrms/ShiftSwaps'));
const ImprovementPlans = lazy(() => import('./pages/hrms/ImprovementPlans'));
const DevelopmentPlans = lazy(() => import('./pages/hrms/DevelopmentPlans'));
const Okrs = lazy(() => import('./pages/hrms/Okrs'));
const Careers = lazy(() => import('./pages/hrms/Careers'));
const Signatures = lazy(() => import('./pages/hrms/Signatures'));
const SignatureVerify = lazy(() => import('./pages/SignatureVerify'));
const Loans = lazy(() => import('./pages/hrms/Loans'));
const OrgChart = lazy(() => import('./pages/hrms/OrgChart'));
const Letters = lazy(() => import('./pages/hrms/Letters'));
const OffboardingCaseDetail = lazy(() => import('./pages/hrms/OffboardingCaseDetail'));
const Attendance = lazy(() => import('./pages/hrms/Attendance'));
const AttendanceApprovals = lazy(() => import('./pages/hrms/AttendanceApprovals'));
const Leave = lazy(() => import('./pages/hrms/Leave'));
const MyLeave = lazy(() => import('./pages/hrms/MyLeave'));
const CompOff = lazy(() => import('./pages/hrms/CompOff'));
const MyCompOff = lazy(() => import('./pages/hrms/MyCompOff'));
const Holidays = lazy(() => import('./pages/hrms/Holidays'));
const Shifts = lazy(() => import('./pages/hrms/Shifts'));
const Compensation = lazy(() => import('./pages/hrms/Compensation'));
const Payroll = lazy(() => import('./pages/hrms/Payroll'));
const PayrollRunDetail = lazy(() => import('./pages/hrms/PayrollRunDetail'));
const MyPayslips = lazy(() => import('./pages/hrms/MyPayslips'));
const Statutory = lazy(() => import('./pages/hrms/Statutory'));
const Expenses = lazy(() => import('./pages/hrms/Expenses'));
const MyExpenses = lazy(() => import('./pages/hrms/MyExpenses'));
const MyHr = lazy(() => import('./pages/MyHr'));
const MyTeam = lazy(() => import('./pages/hrms/MyTeam'));
const Performance = lazy(() => import('./pages/hrms/Performance'));
const PerformanceCycleDetail = lazy(() => import('./pages/hrms/PerformanceCycleDetail'));
const MyPerformance = lazy(() => import('./pages/hrms/MyPerformance'));
const Skills = lazy(() => import('./pages/hrms/Skills'));
const Talent = lazy(() => import('./pages/hrms/Talent'));
const Assets = lazy(() => import('./pages/hrms/Assets'));
const MyAssets = lazy(() => import('./pages/hrms/MyAssets'));
const Engagement = lazy(() => import('./pages/hrms/Engagement'));
const MySurvey = lazy(() => import('./pages/hrms/MySurvey'));
const Analytics = lazy(() => import('./pages/hrms/Analytics'));
const AuditLog = lazy(() => import('./pages/hrms/AuditLog'));
const Search = lazy(() => import('./pages/Search'));
const Notifications = lazy(() => import('./pages/Notifications'));
const Tenants = lazy(() => import('./pages/Tenants'));
const TenantDetail = lazy(() => import('./pages/TenantDetail'));
const TenantIntake = lazy(() => import('./pages/TenantIntake'));
const Plans = lazy(() => import('./pages/Plans'));
const Subscription = lazy(() => import('./pages/Subscription'));
const DataExport = lazy(() => import('./pages/DataExport'));
const SystemDashboard = lazy(() => import('./pages/SystemDashboard'));
const SystemUsers = lazy(() => import('./pages/SystemUsers'));
const SystemSettings = lazy(() => import('./pages/SystemSettings'));
const AuditLogs = lazy(() => import('./pages/AuditLogs'));
const FeatureManagement = lazy(() => import('./pages/FeatureManagement'));
const AdminQueue = lazy(() => import('./pages/AdminQueue'));
const AdminActivity = lazy(() => import('./pages/AdminActivity'));
const FeatureFlags = lazy(() => import('./pages/FeatureFlags'));
const Security = lazy(() => import('./pages/Security'));
const CmsPages = lazy(() => import('./pages/CmsPages'));
const MediaLibrary = lazy(() => import('./pages/MediaLibrary'));
const WebsiteSettings = lazy(() => import('./pages/WebsiteSettings'));
const BlogPosts = lazy(() => import('./pages/BlogPosts'));
const Forbidden = lazy(() => import('./pages/Forbidden'));
const Maintenance = lazy(() => import('./pages/Maintenance'));
const ModuleDenied = lazy(() => import('./pages/ModuleDenied'));
const NotFound = lazy(() => import('./pages/NotFound'));
import { homeRouteFor } from './utils/deepLinks';

function LoadingScreen() {
    return (
        <AppSkeleton />
    );
}

function ScrollToTop() {
    const location = useLocation();

    useEffect(() => {
        window.scrollTo({ top: 0, behavior: 'instant' });
    }, [location.pathname]);

    return null;
}

/**
 * The HRMS hub's invisible shell: crash isolation only, no navigation UI.
 * The hub rail (HrmsLayout) was removed once the HR product shell gained its
 * own sidebar — every HRMS page is reached from there now, so a second nav
 * column only stole content width. The error boundary stays: one broken tab
 * must not take down the whole shell.
 */
function HrmsErrorScope() {
    const location = useLocation();

    return (
        <HrmsErrorBoundary resetKey={location.pathname}>
            <Outlet />
        </HrmsErrorBoundary>
    );
}

function AppRoutes() {
    const { loading, user } = useAuth();
    const userIsSuperAdmin = user?.is_super_admin && !user?.impersonating;

    if (loading) return <LoadingScreen />;

    return (
        <ThemeProvider>
            <Suspense fallback={<AppSkeleton />}>
            <Routes>
                <Route path="/verify-signature/:code" element={<SignatureVerify />} />
                <Route element={<GuestRoute />}>
                    <Route path="/login" element={<Login />} />
                    <Route path="/register" element={<Register />} />
                    <Route path="/register/complete" element={<RegisterComplete />} />
                    <Route path="/forgot-password" element={<ForgotPassword />} />
                    <Route path="/reset-password" element={<ResetPassword />} />
                </Route>

                <Route element={<ProtectedRoute />}>
                    <Route element={<AdminLayout />}>
                        <Route path="/" element={<Navigate to={homeRouteFor(user)} replace />} />
                        <Route path="/onboarding" element={<Onboarding />} />
                        {userIsSuperAdmin && (
                            <Route element={<ProtectedRoute permission="dashboard.view" />}>
                                <Route path="/admin" element={<SystemDashboard />} />
                                {/* Merged into the overview (same payload, one page) — keep old bookmarks working. */}
                                <Route path="/admin/analytics" element={<Navigate to="/admin" replace />} />
                                <Route path="/admin/users" element={<SystemUsers />} />
                                <Route path="/admin/settings" element={<SystemSettings />} />
                                <Route path="/admin/audit-logs" element={<AuditLogs />} />
                                <Route path="/admin/support" element={<AdminSupport />} />
                                <Route path="/admin/support/:ticketId" element={<AdminSupport />} />
                                <Route path="/admin/leads" element={<Leads />} />
                                <Route path="/admin/features" element={<FeatureManagement />} />
                                <Route path="/admin/feature-flags" element={<FeatureFlags />} />
                                <Route path="/admin/queue" element={<AdminQueue />} />
                                <Route path="/admin/activity" element={<AdminActivity />} />
                                <Route path="/admin/pages" element={<CmsPages />} />
                                <Route path="/admin/media" element={<MediaLibrary />} />
                                <Route path="/admin/site-settings" element={<WebsiteSettings />} />
                                <Route path="/admin/blog" element={<BlogPosts />} />
                                <Route path="/tenants" element={<Tenants />} />
                                <Route path="/tenants/new" element={<TenantIntake />} />
                                <Route path="/tenants/:tenantId/setup" element={<TenantIntake />} />
                                <Route path="/tenants/:tenantId" element={<TenantDetail />} />
                                <Route path="/plans" element={<Plans />} />
                            </Route>
                        )}
                        <Route element={<ProtectedRoute permission="dashboard.view" />}>
                            {/* A platform super admin has no tenant context, so the tenant
                                dashboard endpoints 403 — send them to the platform overview. */}
                            <Route
                                path="/dashboard"
                                element={homeRouteFor(user) !== '/dashboard' ? <Navigate to={homeRouteFor(user)} replace /> : <Dashboard />}
                            />
                        </Route>
                        <Route path="/notifications" element={<Notifications />} />
                        <Route path="/security" element={<Security />} />
                        <Route
                            path="/subscription"
                            element={
                                <ProtectedRoute permission="billing.view">
                                    <Subscription />
                                </ProtectedRoute>
                            }
                        />
                        {/* Phase 5: full data export, gated by export.full add-on module. */}
                        <Route element={<ProtectedRoute permission="billing.view" module="export.full" />}>
                            <Route path="/export" element={<DataExport />} />
                        </Route>
                        {/* My HR home (P17.5): the employee surface, module-gated
                            like every self-service page. */}
                        <Route element={<ProtectedRoute module="hrms.core" />}>
                            <Route path="/my" element={<MyHr />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="workspaces.view" product="tms" />}>
                            <Route path="/workspaces" element={<Workspaces />} />
                            <Route path="/workspaces/:workspaceId" element={<WorkspaceDetail />} />
                            <Route path="/projects" element={<Projects />} />
                            <Route path="/projects/:projectId" element={<ProjectDetail />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="workspaces.view" product="tms" module="global_search" />}>
                            <Route path="/search" element={<Search />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="workspaces.view" product="tms" module="timesheets" />}>
                            <Route path="/timesheets" element={<Timesheets />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="reports.view" product="tms" module="reports" />}>
                            <Route path="/reports" element={<Reports />} />
                            <Route path="/reports/builder" element={<ReportBuilder />} />
                        </Route>
                        <Route element={<ProtectedRoute module="hrms.core" />}>
                            <Route path="/hrms" element={<HrmsErrorScope />}>
                                <Route index element={<HrmsOverview />} />
                                {/* The queue sits first: it is self-scoped, so it
                                    needs the module and nothing else. */}
                                <Route path="/hrms/inbox" element={<Inbox />} />
                                {/* The manager telescope (P17.5): read-only by
                                    construction, module-gated — managers may hold
                                    no other permission and still see reports. */}
                                <Route path="/hrms/team" element={<MyTeam />} />                            {/* Before the `:section` catch-all: a static segment
                                    outranks a dynamic one, but relying on the router's
                                    ranking to keep the directory reachable is a trap for
                                    whoever adds the next HRMS page. */}
                                <Route
                                    path="/hrms/employees"
                                    element={
                                        <ProtectedRoute permission="hrms.employees.view">
                                            <Employees />
                                        </ProtectedRoute>
                                    }
                                />
                                <Route
                                    path="/hrms/employees/:employeeId"
                                    element={
                                        <ProtectedRoute permission="hrms.employees.view">
                                            <EmployeeDetail />
                                        </ProtectedRoute>
                                    }
                                />
                                {/* Before the `:section` catch-all for the same
                                    reason the directory is: a static segment has to
                                    be declared, not left to the router's ranking. */}
                                <Route
                                    path="/hrms/org"
                                    element={
                                        <ProtectedRoute permission="hrms.org.view">
                                            <Org />
                                        </ProtectedRoute>
                                    }
                                />
                                {/* The document store and the self-service files
                                    page, declared before `:section` like every
                                    other static HRMS segment. */}
                                <Route
                                    path="/hrms/documents"
                                    element={
                                        <ProtectedRoute permission="hrms.documents.view">
                                            <Documents />
                                        </ProtectedRoute>
                                    }
                                />
                                <Route path="/hrms/documents/mine" element={<MyDocuments />} />
                                {/* Lifecycle runs: the lists are HR screens, the
                                    case pages are module-gated so a hire can open
                                    their own run — the backend policy, not the
                                    route, decides whose case it is. */}
                                <Route
                                    path="/hrms/onboarding"
                                    element={
                                        <ProtectedRoute permission="hrms.onboarding.view">
                                            <OnboardingCases />
                                        </ProtectedRoute>
                                    }
                                />
                                <Route path="/hrms/onboarding/cases/:caseId" element={<OnboardingCaseDetail />} />
                                <Route path="/hrms/resignation" element={<ProtectedRoute module="hrms.offboarding" />}>
                                    <Route index element={<Resignation />} />
                                </Route>
                                <Route path="/hrms/changes" element={<EmployeeChanges />} />
                                <Route path="/hrms/careers" element={<ProtectedRoute module="hrms.talent" />}>
                                    <Route index element={<Careers />} />
                                </Route>
                                <Route path="/hrms/signatures" element={<ProtectedRoute module="hrms.documents" />}>
                                    <Route index element={<Signatures />} />
                                </Route>
                                <Route path="/hrms/okrs" element={<ProtectedRoute module="hrms.performance" />}>
                                    <Route index element={<Okrs />} />
                                </Route>
                                <Route path="/hrms/development-plans" element={<ProtectedRoute module="hrms.performance" />}>
                                    <Route index element={<DevelopmentPlans />} />
                                </Route>
                                <Route path="/hrms/improvement-plans" element={<ProtectedRoute module="hrms.performance" />}>
                                    <Route index element={<ImprovementPlans />} />
                                </Route>
                                <Route path="/hrms/loans" element={<ProtectedRoute module="hrms.payroll" />}>
                                    <Route index element={<Loans />} />
                                </Route>
                                <Route path="/hrms/letters" element={<ProtectedRoute module="hrms.documents" permission="hrms.documents.manage" />}>
                                    <Route index element={<Letters />} />
                                </Route>
                                <Route path="/hrms/org-chart" element={<ProtectedRoute permission="hrms.employees.view" />}>
                                    <Route index element={<OrgChart />} />
                                </Route>
                                <Route path="/hrms/shift-swaps" element={<ProtectedRoute module="hrms.shifts" />}>
                                    <Route index element={<ShiftSwaps />} />
                                </Route>
                                <Route path="/hrms/automation" element={<ProtectedRoute module="automation" permission="hrms.onboarding.manage" />}>
                                    <Route index element={<HrAutomation />} />
                                </Route>
                                <Route path="/hrms/exit-interview" element={<ProtectedRoute module="hrms.offboarding" />}>
                                    <Route index element={<ExitInterview />} />
                                </Route>
                                <Route
                                    path="/hrms/offboarding"
                                    element={
                                        <ProtectedRoute permission="hrms.offboarding.view">
                                            <OffboardingCases />
                                        </ProtectedRoute>
                                    }
                                />
                                <Route path="/hrms/offboarding/cases/:caseId" element={<OffboardingCaseDetail />} />
                                {/* Attendance: the month page is module-gated so
                                    every employee reaches their own curve, while
                                    the review queue needs the regularize
                                    permission — the backend policy, not the
                                    route, decides whose ask is whose. */}
                                <Route element={<ProtectedRoute module="hrms.attendance" />}>
                                    <Route path="/hrms/attendance" element={<Attendance />} />
                                    <Route
                                        path="/hrms/attendance/approvals"
                                        element={
                                            <ProtectedRoute permission="hrms.attendance.regularize">
                                                <AttendanceApprovals />
                                            </ProtectedRoute>
                                        }
                                    />
                                </Route>
                                {/* Leave: the admin hub needs the manage
                                    permission, the self-service page rides the
                                    module alone like My files. */}
                                <Route element={<ProtectedRoute module="hrms.leave" />}>
                                    <Route
                                        path="/hrms/leave"
                                        element={
                                            <ProtectedRoute permission="hrms.leave.manage">
                                                <Leave />
                                            </ProtectedRoute>
                                        }
                                    />
                                    <Route path="/hrms/leave/mine" element={<MyLeave />} />
                                </Route>
                                {/* Comp-off: same split — the hub needs the manage
                                    permission, the self-service page rides the
                                    module alone. */}
                                <Route element={<ProtectedRoute module="hrms.comp_off" />}>
                                    <Route
                                        path="/hrms/comp-off"
                                        element={
                                            <ProtectedRoute permission="hrms.comp_off.manage">
                                                <CompOff />
                                            </ProtectedRoute>
                                        }
                                    />
                                    <Route path="/hrms/comp-off/mine" element={<MyCompOff />} />
                                </Route>
                                {/* Holidays: shared reference data with internal
                                    gating — the route needs the module, reads
                                    ride it, mutations hide without manage. */}
                                <Route
                                    path="/hrms/holidays"
                                    element={
                                        <ProtectedRoute module="hrms.holidays">
                                            <Holidays />
                                        </ProtectedRoute>
                                    }
                                />
                                {/* Shifts & rosters: module-gated; reads ride the
                                    module, mutations hide without manage. */}
                                <Route
                                    path="/hrms/shifts"
                                    element={
                                        <ProtectedRoute module="hrms.shifts">
                                            <Shifts />
                                        </ProtectedRoute>
                                    }
                                />
                                {/* Compensation: heads and templates need the
                                    view permission, the salary picker degrades
                                    without the directory. Payroll: the hub and
                                    the run detail are runner tools; my-payslips
                                    rides the module alone like My files. */}
                                {/* Compensation: the page rides the compensation
                                    module on top of core, matching the API
                                    gate — a core-only plan loses the sidebar
                                    entry and the route together. */}
                                <Route element={<ProtectedRoute module="hrms.compensation" />}>
                                    <Route
                                        path="/hrms/compensation"
                                        element={
                                            <ProtectedRoute permission="hrms.compensation.view">
                                                <Compensation />
                                            </ProtectedRoute>
                                        }
                                    />
                                </Route>
                                {/* Payroll: runs, run detail and my-payslips
                                    all ride the payroll module, matching the
                                    API gate. */}
                                <Route element={<ProtectedRoute module="hrms.payroll" />}>
                                    <Route
                                        path="/hrms/payroll"
                                        element={
                                            <ProtectedRoute permission="hrms.payroll.run">
                                                <Payroll />
                                            </ProtectedRoute>
                                        }
                                    />
                                    <Route
                                        path="/hrms/payroll/runs/:runId"
                                        element={
                                            <ProtectedRoute permission="hrms.payroll.run">
                                                <PayrollRunDetail />
                                            </ProtectedRoute>
                                        }
                                    />
                                    <Route path="/hrms/payroll/mine" element={<MyPayslips />} />
                                </Route>
                                {/* Statutory: the whole page rides the statutory
                                    module; internal gating splits rulebooks and
                                    decisions (manage) from claims filing and
                                    masked reads. */}
                                <Route element={<ProtectedRoute module="hrms.payroll.statutory" />}>
                                    <Route path="/hrms/statutory" element={<Statutory />} />
                                </Route>
                                {/* Expenses: the queue needs the view permission,
                                    the self-service page rides the module alone
                                    like My files. */}
                                <Route element={<ProtectedRoute module="hrms.expenses" />}>
                                    <Route
                                        path="/hrms/expenses"
                                        element={
                                            <ProtectedRoute permission="hrms.expenses.view">
                                                <Expenses />
                                            </ProtectedRoute>
                                        }
                                    />
                                    <Route path="/hrms/expenses/mine" element={<MyExpenses />} />
                                </Route>
                                {/* Performance: the hub and the cycle detail need
                                    the view permission, the self-service page
                                    rides the module alone like My files. */}
                                <Route element={<ProtectedRoute module="hrms.performance" />}>
                                    <Route
                                        path="/hrms/performance"
                                        element={
                                            <ProtectedRoute permission="hrms.performance.view">
                                                <Performance />
                                            </ProtectedRoute>
                                        }
                                    />
                                    <Route
                                        path="/hrms/performance/cycles/:cycleId"
                                        element={
                                            <ProtectedRoute permission="hrms.performance.view">
                                                <PerformanceCycleDetail />
                                            </ProtectedRoute>
                                        }
                                    />
                                    <Route path="/hrms/performance/mine" element={<MyPerformance />} />
                                </Route>
                                {/* Talent: the calibration room is manage-only
                                    end to end — the grid names reviewees and
                                    their ratings, so there is no viewer tier. */}
                                <Route element={<ProtectedRoute module="hrms.talent" />}>
                                    <Route path="/hrms/skills" element={<Skills />} />
                                    <Route
                                        path="/hrms/talent"
                                        element={
                                            <ProtectedRoute permission="hrms.talent.manage">
                                                <Talent />
                                            </ProtectedRoute>
                                        }
                                    />
                                </Route>
                                {/* Assets: the register needs the view permission,
                                    the self-service page rides the module alone
                                    like My files. */}
                                <Route element={<ProtectedRoute module="hrms.assets" />}>
                                    <Route
                                        path="/hrms/assets"
                                        element={
                                            <ProtectedRoute permission="hrms.assets.view">
                                                <Assets />
                                            </ProtectedRoute>
                                        }
                                    />
                                    <Route path="/hrms/assets/mine" element={<MyAssets />} />
                                </Route>
                                {/* Engagement: the hub needs the view permission,
                                    answering rides the module alone like My files. */}
                                <Route element={<ProtectedRoute module="hrms.engagement" />}>
                                    <Route
                                        path="/hrms/engagement"
                                        element={
                                            <ProtectedRoute permission="hrms.engagement.view">
                                                <Engagement />
                                            </ProtectedRoute>
                                        }
                                    />
                                    <Route path="/hrms/engagement/mine" element={<MySurvey />} />
                                    <Route path="/hrms/engagement/mine/:campaignId" element={<MySurvey />} />
                                </Route>
                                {/* Analytics: the workforce dashboards need the
                                    analytics view permission; each tab gates
                                    itself against its own domain permission. */}
                                <Route element={<ProtectedRoute module="hrms.analytics" />}>
                                    <Route
                                        path="/hrms/analytics"
                                        element={
                                            <ProtectedRoute permission="hrms.analytics.view">
                                                <Analytics />
                                            </ProtectedRoute>
                                        }
                                    />
                                </Route>
                                {/* Audit: the append-only trail needs the audit
                                    permission; the record view hangs off it. */}
                                <Route
                                    path="/hrms/audit"
                                    element={
                                        <ProtectedRoute permission="hrms.audit.view">
                                            <AuditLog />
                                        </ProtectedRoute>
                                    }
                                />
                                {/* Approval chains (Phase 23): the tenant's
                                    editable chains live here, not scattered
                                    across the domain hubs. Manage-only, like
                                    the API policy — the page 403s otherwise. */}
                                <Route
                                    path="/hrms/approvals/chains"
                                    element={
                                        <ProtectedRoute permission="hrms.approvals.manage">
                                            <ApprovalChains />
                                        </ProtectedRoute>
                                    }
                                />
                                <Route path="/hrms/:section" element={<HrmsOverview />} />
                            </Route>
                        </Route>
                        <Route element={<ProtectedRoute permission="users.view" />}>
                            <Route path="/users" element={<Users />} />
                            <Route path="/users/:userId" element={<UserEdit />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="users.manage" />}>
                            <Route path="/users/import" element={<UserImport />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="custom_fields.manage" module="custom_fields" />}>
                            <Route path="/custom-fields" element={<CustomFields />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="tenant.manage" />}>
                            <Route path="/audit" element={<AuditTimeline />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="webhooks.manage" module="webhooks" />}>
                            <Route path="/webhooks" element={<Webhooks />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="support.manage" />}>
                            <Route path="/support" element={<Support />} />
                            <Route path="/support/:ticketId" element={<Support />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="roles.view" />}>
                            <Route path="/roles" element={<Roles />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="settings.view" />}>
                            <Route path="/settings" element={<Settings />} />
                        </Route>
                    </Route>
                </Route>

                <Route path="/403" element={<Forbidden />} />
                <Route path="/maintenance" element={<Maintenance />} />
                <Route path="/module-denied" element={<ModuleDenied />} />
                <Route path="*" element={<NotFound />} />
            </Routes>
            </Suspense>
        </ThemeProvider>
    );
}

export default function App() {
    return (
        <BrowserRouter basename="/app">
            <ScrollToTop />
            <AuthProvider>
                <ToastProvider>
                    <NotificationProvider>
                        <ConfirmProvider>
                            <AppRoutes />
                        </ConfirmProvider>
                    </NotificationProvider>
                </ToastProvider>
            </AuthProvider>
        </BrowserRouter>
    );
}
