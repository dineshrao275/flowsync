import { useEffect, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import ThemeSettingsDrawer from './ThemeSettingsDrawer';
import ImpersonationBanner from './ImpersonationBanner';
import Breadcrumbs from './Breadcrumbs';
import CommandPalette from './search/CommandPalette';
import { BreadcrumbProvider } from '../context/BreadcrumbContext';
import { PageTitleProvider } from '../context/PageTitleContext';
import { useAuth } from '../context/AuthContext';

const COLLAPSE_KEY = 'flowsync.sidebar.collapsed';

export default function AdminLayout() {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const [themeOpen, setThemeOpen] = useState(false);
    const { check, user } = useAuth();
    const location = useLocation();
    const navigate = useNavigate();
    const showSearch = check('workspaces.view') && check('module:global_search');
    const showTheme = check('module:branding');

    // Route tenants with an unfinished onboarding wizard to the wizard. Tenants
    // that never started onboarding (admin/seed provisioned) and super admins are
    // unaffected (onboarding_complete === true).
    useEffect(() => {
        if (user && user.onboarding_complete === false && location.pathname !== '/onboarding') {
            navigate('/onboarding', { replace: true });
        }
    }, [user, location.pathname, navigate]);

    const [collapsed, setCollapsed] = useState(() => {
        try {
            return localStorage.getItem(COLLAPSE_KEY) === '1';
        } catch {
            return false;
        }
    });

    function toggleCollapse() {
        setCollapsed((current) => {
            const next = !current;
            try {
                localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0');
            } catch {
                /* ignore storage errors */
            }
            return next;
        });
    }

    return (
        <PageTitleProvider>
            <div style={{ backgroundColor: 'var(--dashboard-bg)' }}>
                <ImpersonationBanner />
            <Sidebar
                open={sidebarOpen}
                collapsed={collapsed}
                onClose={() => setSidebarOpen(false)}
                onToggleCollapse={toggleCollapse}
            />

            <div
                className={`flex min-h-screen flex-col transition-[padding] duration-300 ${
                    collapsed ? 'lg:pl-16' : 'lg:pl-64'
                }`}
            >
                <Topbar
                    onOpenTheme={() => setThemeOpen(true)}
                    onToggleSidebar={() => setSidebarOpen((open) => !open)}
                    onOpenSearch={() => setSearchOpen(true)}
                    showSearch={showSearch}
                    showTheme={showTheme}
                />

                <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    <BreadcrumbProvider>
                        <div key={location.pathname} className="animate-fade-in-up">
                            <Breadcrumbs />
                            <Outlet />
                        </div>
                    </BreadcrumbProvider>
                </main>

                <footer
                    className="border-t px-6 py-4 text-center text-xs text-gray-400"
                    style={{ backgroundColor: 'var(--header-bg)' }}
                >
                    FlowSync Admin &middot; Laravel + React
                </footer>
            </div>

            <ThemeSettingsDrawer open={themeOpen} onClose={() => setThemeOpen(false)} />
                <CommandPalette open={searchOpen && showSearch} onClose={() => setSearchOpen(false)} />
            </div>
        </PageTitleProvider>
    );
}