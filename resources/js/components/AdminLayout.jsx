import { Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import ThemeSettingsDrawer from './ThemeSettingsDrawer';
import ImpersonationBanner from './ImpersonationBanner';
import Breadcrumbs from './Breadcrumbs';
import ErrorBoundary from './ErrorBoundary';
import ContentSkeleton from './ui/ContentSkeleton';
import CommandPalette from './search/CommandPalette';
import ShortcutsModal from './ShortcutsModal';
import useGlobalShortcuts from '../hooks/useGlobalShortcuts';
import { BreadcrumbProvider } from '../context/BreadcrumbContext';
import { PageTitleProvider } from '../context/PageTitleContext';
import { useAuth } from '../context/AuthContext';

const STORAGE_PREFIX = (typeof window !== 'undefined' && window.__FLOWSYNC_CONFIG__?.storagePrefix) || 'flowsync';
const COLLAPSE_KEY = `${STORAGE_PREFIX}.sidebar.collapsed`;

export default function AdminLayout() {
    const { t } = useTranslation();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const [themeOpen, setThemeOpen] = useState(false);
    const [helpOpen, setHelpOpen] = useState(false);
    const mainRef = useRef(null);
    const firstRoute = useRef(true);
    const { check, user } = useAuth();
    const location = useLocation();
    const navigate = useNavigate();
    const showSearch = check('workspaces.view') && check('module:global_search');
    // Both halves of the route: the drawer's SAVE is `PUT /theme`, which takes
    // `permission:settings.theme` on top of `ensure_module:branding` (only the
    // read is module-only). Gating on the module alone drew a palette for e.g.
    // the manager role — which holds `settings.theme` in no default selector —
    // whose every save answered 403.
    const showTheme = check('module:branding') && check('permission:settings.theme');

    // Route tenants with an unfinished onboarding wizard to the wizard. Tenants
    // that never started onboarding (admin/seed provisioned) and super admins are
    // unaffected (onboarding_complete === true).
    useEffect(() => {
        if (user && user.onboarding_complete === false && location.pathname !== '/onboarding') {
            navigate('/onboarding', { replace: true });
        }
    }, [user, location.pathname, navigate]);

    // A trial or subscription that ended with no paid plan confines the session to billing (the server answers 402 for
    // everything else); send the person where they can fix it instead of a page full of errors.
    useEffect(() => {
        const allowed = ['/subscription', '/support', '/security'];
        if (user?.subscription_required && !allowed.some((p) => location.pathname === p || location.pathname.startsWith(`${p}/`))) {
            navigate('/subscription', { replace: true });
        }
    }, [user, location.pathname, navigate]);

    // After a client-side navigation focus would otherwise stay on the link that
    // was clicked (now gone); move it to <main> so screen readers announce the
    // new page and the next Tab starts in its content. Skipped on first paint.
    useEffect(() => {
        if (firstRoute.current) {
            firstRoute.current = false;
            return;
        }
        mainRef.current?.focus({ preventScroll: true });
    }, [location.pathname]);

    // The mobile drawer must not outlive a resize to desktop: it would leave the
    // page behind it inert with no way to dismiss it.
    useEffect(() => {
        if (typeof window.matchMedia !== 'function') return undefined;
        const query = window.matchMedia('(min-width: 1024px)');
        const onChange = (event) => event.matches && setSidebarOpen(false);
        query.addEventListener?.('change', onChange);

        return () => query.removeEventListener?.('change', onChange);
    }, []);

    const closeSidebar = useCallback(() => setSidebarOpen(false), []);

    const canTasks = check('workspaces.view') && check('product:tms');
    const goTo = useCallback((path) => () => navigate(path), [navigate]);
    const go = useMemo(() => ({
        ...(canTasks && check('dashboard.view') ? { d: goTo('/dashboard') } : {}),
        ...(canTasks ? { p: goTo('/projects'), w: goTo('/workspaces') } : {}),
        n: goTo('/notifications'),
    }), [canTasks, check, goTo]);

    useGlobalShortcuts({ onSearch: showSearch ? () => setSearchOpen(true) : undefined, onHelp: () => setHelpOpen(true), go });

    const shortcutRows = [
        ...(showSearch ? [{ label: t('shell.shortcuts.search'), keys: ['/'] }, { label: t('shell.shortcuts.searchPalette'), keys: ['Ctrl K'] }] : []),
        ...(go.d ? [{ label: t('shell.shortcuts.goDashboard'), keys: ['g', 'd'] }] : []),
        ...(go.p ? [{ label: t('shell.shortcuts.goProjects'), keys: ['g', 'p'] }, { label: t('shell.shortcuts.goWorkspaces'), keys: ['g', 'w'] }] : []),
        { label: t('shell.shortcuts.goNotifications'), keys: ['g', 'n'] },
        { label: t('shell.shortcuts.help'), keys: ['?'] },
    ];

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
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[90] focus:rounded-lg focus:bg-surface focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-fg focus:shadow-popover"
                >
                    {t('shell.skipToContent')}
                </a>
                <ImpersonationBanner />
            <Sidebar
                open={sidebarOpen}
                collapsed={collapsed}
                onClose={closeSidebar}
                onToggleCollapse={toggleCollapse}
            />

            <div
                // While the phone drawer is open the page behind it is inert (no tab
                // stops, hidden from AT); the drawer traps nothing because it is the
                // only interactive region left.
                inert={sidebarOpen ? true : undefined}
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

                <main id="main-content" ref={mainRef} tabIndex={-1} className="flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:px-8">
                    <BreadcrumbProvider>
                        <div key={location.pathname} className="animate-fade-in-up">
                            <Breadcrumbs />
                            <ErrorBoundary resetKey={location.pathname}>
                                <Suspense fallback={<ContentSkeleton size="lg" />}>
                                    <Outlet />
                                </Suspense>
                            </ErrorBoundary>
                        </div>
                    </BreadcrumbProvider>
                </main>

                <footer
                    className="border-t px-6 py-4 text-center text-caption text-fg-subtle"
                    style={{ backgroundColor: 'var(--header-bg)' }}
                >
                    {t('shell.footer')}
                </footer>
            </div>

            <ThemeSettingsDrawer open={themeOpen} onClose={() => setThemeOpen(false)} />
                <CommandPalette open={searchOpen && showSearch} onClose={() => setSearchOpen(false)} />
                <ShortcutsModal open={helpOpen} onClose={() => setHelpOpen(false)} rows={shortcutRows} />
            </div>
        </PageTitleProvider>
    );
}