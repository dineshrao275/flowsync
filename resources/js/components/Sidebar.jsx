import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router-dom';
import { CaretDoubleLeft, CaretDoubleRight, CaretDown } from '@phosphor-icons/react';
import { useAuth } from '../context/AuthContext';
import { useNotifications } from '../context/NotificationContext';
import { hrmsSections, superAdminSections, tmsSections } from '../utils/nav';
import { productForPath } from '../utils/product';
import { getAppName } from '../utils/appName';

// Sidebar sections live in utils/nav.js: `tmsSections` for the task shell,
// `hrmsSections` (derived from the HRMS_NAV hub catalog, never re-listed)
// for the HR shell, `superAdminSections` for the platform. The component
// below picks by the current path and filters by capability.

// Glyphs are Phosphor components from the nav catalog (regular weight,
// currentColor) — one icon family at one optical size across the shell.
function ItemIcon({ icon }) {
    const Glyph = icon;

    if (!Glyph) return null;

    return <Glyph size={20} weight="regular" className="shrink-0" aria-hidden="true" />;
}

function SidebarLink({ item, collapsed, onClose }) {
    const { t } = useTranslation();
    const location = useLocation();
    const { inboxUnread, unreadCount, effectivePlatformCounts } = useNotifications();
    const isActive = location.pathname === item.to;
    const labelClass = collapsed ? 'hidden lg:hidden' : 'block';

    let badgeCount = 0;
    if (item.badge === 'inbox') badgeCount = inboxUnread;
    else if (item.badge === 'notifications') badgeCount = unreadCount;
    else if (item.badge === 'leads') badgeCount = effectivePlatformCounts?.leads ?? 0;
    else if (item.badge === 'support') badgeCount = effectivePlatformCounts?.support ?? 0;
    else if (item.badge === 'queue') badgeCount = effectivePlatformCounts?.queue ?? 0;

    return (
        <Link
            to={item.to}
            onClick={onClose}
            aria-current={isActive ? 'page' : undefined}
            title={collapsed ? t(item.label) : undefined}
            aria-label={collapsed ? t(item.label) : undefined}
            className={`group relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150 ${
                isActive ? 'bg-[var(--active-menu)]' : 'hover:bg-[var(--sidebar-hover)]'
            } ${collapsed ? 'justify-center lg:justify-center' : ''}`}
            style={{ color: isActive ? '#ffffff' : 'var(--sidebar-text)' }}
        >
            {isActive && (
                <span
                    className={`absolute left-0 top-1/2 h-6 w-1 -translate-y-1/2 rounded-full bg-white transition-opacity ${
                        collapsed ? 'hidden lg:hidden' : 'block'
                    }`}
                    aria-hidden="true"
                />
            )}
            <span className="relative flex items-center justify-center">
                <ItemIcon icon={item.icon} />
                {collapsed && badgeCount > 0 && (
                    <span
                        className="absolute -right-1.5 -top-1.5 flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-none text-white shadow-sm"
                        aria-hidden="true"
                    >
                        {badgeCount > 99 ? '99+' : badgeCount}
                    </span>
                )}
            </span>
            <span className={`min-w-0 flex-1 ${labelClass}`}>
                <span className="flex items-center justify-between gap-2">
                    <span className="truncate">{t(item.label)}</span>
                    {badgeCount > 0 && (
                        <span className="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-red-600 px-1 text-caption font-bold normal-case tracking-normal text-white">
                            {badgeCount > 99 ? '99+' : badgeCount}
                        </span>
                    )}
                </span>
            </span>
        </Link>
    );
}

function SidebarSection({ section, collapsed, onClose, canViewPlans }) {
    const { t } = useTranslation();

    return (
        <div className="space-y-0.5">
            {!collapsed && (
                <p
                    className="flex items-center justify-between px-3 pb-1.5 pt-4 text-xs font-semibold normal-case tracking-normal"
                    style={{ color: 'var(--sidebar-text)', opacity: 0.55 }}
                >
                    <span>{t(section.label)}</span>
                </p>
            )}
            {section.items.length === 0 && !collapsed && (
                <div
                    className="rounded-lg px-3 py-2 text-xs leading-relaxed"
                    style={{ color: 'var(--sidebar-text)', opacity: 0.6 }}
                >
                    <span>{t('shell.sidebar.lockedByPlan')}</span>{' '}
                    {canViewPlans ? (
                        <Link to="/subscription" className="underline transition-colors hover:opacity-80">
                            {t('shell.sidebar.viewPlans')}
                        </Link>
                    ) : (
                        t('shell.sidebar.adminCanChange')
                    )}
                </div>
            )}
            {section.items.map((item) => (
                <SidebarLink key={item.to} item={item} collapsed={collapsed} onClose={onClose} />
            ))}
        </div>
    );
}

/**
 * Whether a sidebar item covers the current path. Detail pages (e.g.
 * `/hrms/employees/7`) belong to their section's tab; `/hrms` itself matches
 * exactly, or every HRMS page would light up the Overview group.
 */
function isItemActive(item, pathname) {
    if (pathname === item.to) return true;
    if (item.to === '/hrms' || item.to === '/my') return false;
    return pathname.startsWith(`${item.to}/`);
}

/**
 * One collapsible HR-shell group (Overview, Workforce, Time, …). Clicking the
 * header expands its related pages inline; the group holding the current page
 * opens on its own. When the sidebar is collapsed there is no room for a
 * submenu, so the group icon navigates straight to its first reachable page.
 */
function CollapsibleSection({ section, collapsed, onClose, open, onToggle }) {
    const { t } = useTranslation();
    const { inboxUnread, unreadCount, effectivePlatformCounts } = useNotifications();
    const { pathname } = useLocation();
    const [first] = section.items;
    const activeInside = section.items.some((item) => isItemActive(item, pathname));

    const getBadgeCount = (item) => {
        if (!item?.badge) return 0;
        if (item.badge === 'inbox') return inboxUnread;
        if (item.badge === 'notifications') return unreadCount;
        if (item.badge === 'leads') return effectivePlatformCounts?.leads ?? 0;
        if (item.badge === 'support') return effectivePlatformCounts?.support ?? 0;
        if (item.badge === 'queue') return effectivePlatformCounts?.queue ?? 0;
        return 0;
    };

    const totalGroupBadgeCount = section.items.reduce((sum, item) => sum + getBadgeCount(item), 0);
    const badge = totalGroupBadgeCount > 0 && (
        <span className="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-red-600 px-1 text-caption font-bold normal-case tracking-normal text-white">
            {totalGroupBadgeCount > 99 ? '99+' : totalGroupBadgeCount}
        </span>
    );

    if (collapsed) {
        if (!first) return null;
        const active = isItemActive(first, pathname) || activeInside;
        return (
            <Link
                to={first.to}
                onClick={onClose}
                aria-current={active ? 'page' : undefined}
                title={t(section.label)}
                aria-label={t(section.label)}
                className={`group relative flex items-center justify-center rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150 ${
                    active ? 'bg-[var(--active-menu)]' : 'hover:bg-[var(--sidebar-hover)]'
                }`}
                style={{ color: active ? '#ffffff' : 'var(--sidebar-text)' }}
            >
                <span className="relative flex items-center justify-center">
                    <ItemIcon icon={first.icon} />
                    {totalGroupBadgeCount > 0 && (
                        <span
                            className="absolute -right-1.5 -top-1.5 flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-none text-white shadow-sm"
                            aria-hidden="true"
                        >
                            {totalGroupBadgeCount > 99 ? '99+' : totalGroupBadgeCount}
                        </span>
                    )}
                </span>
            </Link>
        );
    }

    return (
        <div className="space-y-0.5">
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                title={open ? t('shell.sidebar.collapseGroup', { label: t(section.label) }) : t('shell.sidebar.expandGroup', { label: t(section.label) })}
                className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150 hover:bg-[var(--sidebar-hover)]"
                style={{ color: 'var(--sidebar-text)' }}
            >
                <ItemIcon icon={first?.icon} />
                <span className="min-w-0 flex-1 truncate text-left">{t(section.label)}</span>
                {badge}
                <CaretDown size={16} weight="bold" aria-hidden="true" className={`shrink-0 transition-transform duration-150 ${open ? 'rotate-180' : ''}`} />
            </button>
            {open && (
                <div className="ml-5 space-y-0.5 border-l pl-1.5" style={{ borderColor: 'var(--sidebar-hover)' }}>
                    {section.items.map((item) => (
                        <SidebarLink key={item.to} item={item} collapsed={collapsed} onClose={onClose} />
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Sidebar({ open, collapsed, onClose, onToggleCollapse }) {
    const { t } = useTranslation();
    const asideRef = useRef(null);

    // Mobile drawer focus management: Escape closes it, focus moves into it on
    // open and returns to whatever opened it (the hamburger) on close.
    useEffect(() => {
        if (!open) return undefined;

        const opener = document.activeElement;
        asideRef.current?.querySelector('a, button')?.focus();

        function onKeyDown(event) {
            if (event.key === 'Escape') onClose();
        }
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            if (opener instanceof HTMLElement) opener.focus();
        };
    }, [open, onClose]);

    const { check, user } = useAuth();
    const { pathname } = useLocation();
    const isSuperAdmin = user?.is_super_admin && !user?.impersonating;

    // Explicit per-group open state; a group with no entry falls back to
    // holding the current page open, so deep links and back/forward always
    // land on a visible, highlighted item.
    const [manualGroups, setManualGroups] = useState({});

    // The product shell follows the URL: /hrms* and /my render the HR shell,
    // everything else the task shell. Crossing shells is the topbar
    // ProductSwitcher's job, not the sidebar's.
    const shellSections = isSuperAdmin
        ? superAdminSections
        : productForPath(pathname) === 'hrms' ? hrmsSections : tmsSections;

    // A section that filtered to zero because every item was module-gated
    // (not permission-gated) survives with a "locked by your plan" note —
    // otherwise it would just vanish and read as an admin artifact.
    const items = shellSections
        .map((section) => {
            const filtered = section.items.filter(
                (item) => !item.capabilities?.length || item.capabilities.every((capability) => check(capability)),
            );

            return {
                ...section,
                items: filtered,
                lockedByModule:
                    filtered.length === 0 &&
                    section.items.some((item) =>
                        item.capabilities?.some((capability) => capability.startsWith('module:') && !check(capability)),
                    ),
            };
        })
        .filter((section) => section.items.length > 0 || section.lockedByModule);

    return (
        <>
            {open && (
                <div
                    className="fixed inset-0 z-30 animate-backdrop-in bg-black/50 lg:hidden"
                    onClick={onClose}
                    aria-hidden="true"
                />
            )}

            <aside
                ref={asideRef}
                // Off-canvas on phones: `invisible` takes the closed drawer's links out
                // of the tab order; on desktop it is always on screen.
                className={`fixed inset-y-0 left-0 z-40 flex flex-col transition-all duration-300 lg:visible lg:translate-x-0 ${
                    open ? 'visible translate-x-0' : 'invisible -translate-x-full'
                } ${collapsed ? 'w-16 lg:w-16' : 'w-64'}`}
                style={
                    user?.impersonating
                        ? { backgroundColor: 'var(--sidebar-bg)', top: '2.5rem', height: 'calc(100% - 2.5rem)' }
                        : { backgroundColor: 'var(--sidebar-bg)' }
                }
            >
                <div className={`flex shrink-0 items-center gap-3 px-4 py-5 ${collapsed ? 'justify-center lg:justify-center px-0' : ''}`}>
                    <div
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white shadow-lg"
                        style={{ backgroundColor: 'var(--accent)' }}
                    >
                        F
                    </div>
                    {!collapsed && (
                        <div className="min-w-0">
                            <p className="truncate text-base font-semibold text-white">
                                {getAppName()}
                            </p>
                            <p className="truncate text-xs" style={{ color: 'var(--sidebar-text)' }}>
                                {isSuperAdmin ? t('shell.sidebar.superAdminPanel') : user?.tenant?.name || t('shell.sidebar.adminPanel')}
                            </p>
                        </div>
                    )}
                </div>

                <nav aria-label={t('shell.sidebar.nav')} className="flex-1 space-y-4 overflow-y-auto px-3 py-2">
                    {items.map((section) => {
                        const open = manualGroups[section.label] ?? section.items.some((item) => isItemActive(item, pathname));

                        return section.collapsible && section.items.length > 0 ? (
                            <CollapsibleSection
                                key={section.label}
                                section={section}
                                collapsed={collapsed}
                                onClose={onClose}
                                open={open}
                                onToggle={() => setManualGroups((groups) => ({ ...groups, [section.label]: !open }))}
                            />
                        ) : (
                            <SidebarSection
                                key={section.label}
                                section={section}
                                collapsed={collapsed}
                                onClose={onClose}
                                canViewPlans={check('billing.view')}
                            />
                        );
                    })}
                </nav>

                <div className="border-t px-3 py-3" style={{ borderColor: 'var(--sidebar-hover)' }}>
                    <button
                        type="button"
                        onClick={onToggleCollapse}
                        title={collapsed ? t('shell.sidebar.expandSidebar') : t('shell.sidebar.collapseSidebar')}
                        aria-label={collapsed ? t('shell.sidebar.expandSidebar') : t('shell.sidebar.collapseSidebar')}
                        className={`hidden w-full items-center rounded-lg py-2 text-sm font-medium transition-colors hover:bg-[var(--sidebar-hover)] lg:flex ${
                            collapsed ? 'justify-center' : 'gap-2 px-3'
                        }`}
                        style={{ color: 'var(--sidebar-text)' }}
                    >
                        {collapsed ? (
                            <CaretDoubleRight size={16} weight="bold" aria-hidden="true" className="shrink-0" />
                        ) : (
                            <CaretDoubleLeft size={16} weight="bold" aria-hidden="true" className="shrink-0" />
                        )}
                        {!collapsed && <span>{t('shell.sidebar.collapse')}</span>}
                    </button>
                </div>
            </aside>
        </>
    );
}