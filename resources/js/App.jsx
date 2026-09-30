import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { useEffect } from 'react';
import { AuthProvider, useAuth } from './context/AuthContext';
import { ThemeProvider } from './context/ThemeContext';
import { ToastProvider } from './context/ToastContext';
import { NotificationProvider } from './context/NotificationContext';
import GuestRoute from './components/GuestRoute';
import ProtectedRoute from './components/ProtectedRoute';
import AdminLayout from './components/AdminLayout';
import Spinner from './components/ui/Spinner';

import Login from './pages/auth/Login';
import Register from './pages/auth/Register';
import ForgotPassword from './pages/auth/ForgotPassword';
import ResetPassword from './pages/auth/ResetPassword';
import Onboarding from './pages/Onboarding';
import Dashboard from './pages/Dashboard';
import Workspaces from './pages/Workspaces';
import WorkspaceDetail from './pages/WorkspaceDetail';
import ProjectDetail from './pages/ProjectDetail';
import Projects from './pages/Projects';
import Users from './pages/Users';
import Roles from './pages/Roles';
import Settings from './pages/Settings';
import Reports from './pages/Reports';
import HrmsOverview from './pages/hrms/HrmsOverview';
import Employees from './pages/hrms/Employees';
import EmployeeDetail from './pages/hrms/EmployeeDetail';
import Org from './pages/hrms/Org';
import Documents from './pages/hrms/Documents';
import MyDocuments from './pages/hrms/MyDocuments';
import OnboardingCases from './pages/hrms/OnboardingCases';
import OnboardingCaseDetail from './pages/hrms/OnboardingCaseDetail';
import OffboardingCases from './pages/hrms/OffboardingCases';
import OffboardingCaseDetail from './pages/hrms/OffboardingCaseDetail';
import Attendance from './pages/hrms/Attendance';
import AttendanceApprovals from './pages/hrms/AttendanceApprovals';
import Leave from './pages/hrms/Leave';
import MyLeave from './pages/hrms/MyLeave';
import CompOff from './pages/hrms/CompOff';
import MyCompOff from './pages/hrms/MyCompOff';
import Holidays from './pages/hrms/Holidays';
import Compensation from './pages/hrms/Compensation';
import Payroll from './pages/hrms/Payroll';
import PayrollRunDetail from './pages/hrms/PayrollRunDetail';
import MyPayslips from './pages/hrms/MyPayslips';
import Statutory from './pages/hrms/Statutory';
import Expenses from './pages/hrms/Expenses';
import MyExpenses from './pages/hrms/MyExpenses';
import Performance from './pages/hrms/Performance';
import PerformanceCycleDetail from './pages/hrms/PerformanceCycleDetail';
import MyPerformance from './pages/hrms/MyPerformance';
import Search from './pages/Search';
import Notifications from './pages/Notifications';
import Tenants from './pages/Tenants';
import TenantDetail from './pages/TenantDetail';
import Plans from './pages/Plans';
import Subscription from './pages/Subscription';
import SystemDashboard from './pages/SystemDashboard';
import SystemAnalytics from './pages/SystemAnalytics';
import SystemUsers from './pages/SystemUsers';
import SystemSettings from './pages/SystemSettings';
import AuditLogs from './pages/AuditLogs';
import FeatureManagement from './pages/FeatureManagement';
import CmsPages from './pages/CmsPages';
import Forbidden from './pages/Forbidden';
import NotFound from './pages/NotFound';
import { homeRouteFor } from './utils/deepLinks';

function LoadingScreen() {
    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100">
            <Spinner />
        </div>
    );
}

function ScrollToTop() {
    const location = useLocation();

    useEffect(() => {
        window.scrollTo({ top: 0, behavior: 'instant' });
    }, [location.pathname]);

    return null;
}

function AppRoutes() {
    const { loading, user } = useAuth();
    const userIsSuperAdmin = user?.is_super_admin && !user?.impersonating;

    if (loading) return <LoadingScreen />;

    return (
        <ThemeProvider>
            <Routes>
                <Route element={<GuestRoute />}>
                    <Route path="/login" element={<Login />} />
                    <Route path="/register" element={<Register />} />
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
                                <Route path="/admin/analytics" element={<SystemAnalytics />} />
                                <Route path="/admin/users" element={<SystemUsers />} />
                                <Route path="/admin/settings" element={<SystemSettings />} />
                                <Route path="/admin/audit-logs" element={<AuditLogs />} />
                                <Route path="/admin/features" element={<FeatureManagement />} />
                                <Route path="/admin/pages" element={<CmsPages />} />
                                <Route path="/tenants" element={<Tenants />} />
                                <Route path="/tenants/:tenantId" element={<TenantDetail />} />
                                <Route path="/plans" element={<Plans />} />
                            </Route>
                        )}
                        <Route element={<ProtectedRoute permission="dashboard.view" />}>
                            {/* A platform super admin has no tenant context, so the tenant
                                dashboard endpoints 403 — send them to the platform overview. */}
                            <Route
                                path="/dashboard"
                                element={userIsSuperAdmin ? <Navigate to={homeRouteFor(user)} replace /> : <Dashboard />}
                            />
                        </Route>
                        <Route path="/notifications" element={<Notifications />} />
                        <Route path="/subscription" element={<Subscription />} />
                        <Route element={<ProtectedRoute permission="workspaces.view" />}>
                            <Route path="/workspaces" element={<Workspaces />} />
                            <Route path="/workspaces/:workspaceId" element={<WorkspaceDetail />} />
                            <Route path="/projects" element={<Projects />} />
                            <Route path="/projects/:projectId" element={<ProjectDetail />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="workspaces.view" module="global_search" />}>
                            <Route path="/search" element={<Search />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="reports.view" module="reports" />}>
                            <Route path="/reports" element={<Reports />} />
                        </Route>
                        <Route element={<ProtectedRoute module="hrms.core" />}>
                            <Route path="/hrms" element={<HrmsOverview />} />
                            {/* Before the `:section` catch-all: a static segment
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
                            <Route path="/hrms/attendance" element={<Attendance />} />
                            <Route
                                path="/hrms/attendance/approvals"
                                element={
                                    <ProtectedRoute permission="hrms.attendance.regularize">
                                        <AttendanceApprovals />
                                    </ProtectedRoute>
                                }
                            />
                            {/* Leave: the admin hub needs the manage
                                permission, the self-service page rides the
                                module alone like My files. */}
                            <Route
                                path="/hrms/leave"
                                element={
                                    <ProtectedRoute permission="hrms.leave.manage">
                                        <Leave />
                                    </ProtectedRoute>
                                }
                            />
                            <Route path="/hrms/leave/mine" element={<MyLeave />} />
                            {/* Comp-off: same split — the hub needs the manage
                                permission, the self-service page rides the
                                module alone. */}
                            <Route
                                path="/hrms/comp-off"
                                element={
                                    <ProtectedRoute permission="hrms.comp_off.manage">
                                        <CompOff />
                                    </ProtectedRoute>
                                }
                            />
                            <Route path="/hrms/comp-off/mine" element={<MyCompOff />} />
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
                            {/* Compensation: heads and templates need the
                                view permission, the salary picker degrades
                                without the directory. Payroll: the hub and
                                the run detail are runner tools; my-payslips
                                rides the module alone like My files. */}
                            <Route
                                path="/hrms/compensation"
                                element={
                                    <ProtectedRoute permission="hrms.compensation.view">
                                        <Compensation />
                                    </ProtectedRoute>
                                }
                            />
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
                            <Route
                                path="/hrms/expenses"
                                element={
                                    <ProtectedRoute permission="hrms.expenses.view">
                                        <Expenses />
                                    </ProtectedRoute>
                                }
                            />
                            <Route path="/hrms/expenses/mine" element={<MyExpenses />} />
                            {/* Performance: the hub and the cycle detail need
                                the view permission, the self-service page
                                rides the module alone like My files. */}
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
                            <Route path="/hrms/:section" element={<HrmsOverview />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="users.view" />}>
                            <Route path="/users" element={<Users />} />
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
                <Route path="*" element={<NotFound />} />
            </Routes>
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
                        <AppRoutes />
                    </NotificationProvider>
                </ToastProvider>
            </AuthProvider>
        </BrowserRouter>
    );
}
