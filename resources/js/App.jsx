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
import ForgotPassword from './pages/auth/ForgotPassword';
import ResetPassword from './pages/auth/ResetPassword';
import Dashboard from './pages/Dashboard';
import Workspaces from './pages/Workspaces';
import WorkspaceDetail from './pages/WorkspaceDetail';
import ProjectDetail from './pages/ProjectDetail';
import Projects from './pages/Projects';
import Users from './pages/Users';
import Roles from './pages/Roles';
import Settings from './pages/Settings';
import Reports from './pages/Reports';
import Search from './pages/Search';
import Notifications from './pages/Notifications';
import Tenants from './pages/Tenants';
import TenantDetail from './pages/TenantDetail';
import Plans from './pages/Plans';
import Forbidden from './pages/Forbidden';
import NotFound from './pages/NotFound';

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
                    <Route path="/forgot-password" element={<ForgotPassword />} />
                    <Route path="/reset-password" element={<ResetPassword />} />
                </Route>

                <Route element={<ProtectedRoute />}>
                    <Route element={<AdminLayout />}>
                        <Route path="/" element={<Navigate to="/dashboard" replace />} />
                        {userIsSuperAdmin && (
                            <Route element={<ProtectedRoute permission="dashboard.view" />}>
                                <Route path="/tenants" element={<Tenants />} />
                                <Route path="/tenants/:tenantId" element={<TenantDetail />} />
                                <Route path="/plans" element={<Plans />} />
                            </Route>
                        )}
                        <Route element={<ProtectedRoute permission="dashboard.view" />}>
                            <Route path="/dashboard" element={<Dashboard />} />
                        </Route>
                        <Route path="/notifications" element={<Notifications />} />
                        <Route element={<ProtectedRoute permission="workspaces.view" />}>
                            <Route path="/workspaces" element={<Workspaces />} />
                            <Route path="/workspaces/:workspaceId" element={<WorkspaceDetail />} />
                            <Route path="/projects" element={<Projects />} />
                            <Route path="/projects/:projectId" element={<ProjectDetail />} />
                            <Route path="/search" element={<Search />} />
                        </Route>
                        <Route element={<ProtectedRoute permission="reports.view" />}>
                            <Route path="/reports" element={<Reports />} />
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
        <BrowserRouter>
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
