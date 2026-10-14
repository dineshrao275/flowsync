import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useNotifications } from '../context/NotificationContext';
import { hrmsSections, superAdminSections, tmsSections } from '../utils/nav';
import { productForPath } from '../utils/product';

// Sidebar sections live in utils/nav.js: `tmsSections` for the task shell,
// `hrmsSections` (derived from the HRMS_NAV hub catalog, never re-listed)
// for the HR shell, `superAdminSections` for the platform. The component
// below picks by the current path and filters by capability.

function Icon({ path, className = '' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d={path} />
        </svg>
    );
}

function SidebarLink({ item, collapsed, onClose }) {
    const location = useLocation();
    const { inboxUnread } = useNotifications();
    const isActive = location.pathname === item.to;
    const labelClass = collapsed ? 'hidden lg:hidden' : 'block';

    return (
        <Link
            to={item.to}
            onClick={onClose}
            role="menuitem"
            aria-current={isActive ? 'page' : undefined}
            title={collapsed ? item.label : undefined}
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
            <Icon path={item.icon} className="h-5 w-5 shrink-0" />
            <span className={`min-w-0 ${labelClass}`}>
                <span className="flex items-center justify-between gap-2">
                    <span className="truncate">{item.label}</span>
                    {item.badge === 'inbox' && inboxUnread > 0 && (
                        <span className="flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold normal-case tracking-normal text-white">
                            {inboxUnread > 99 ? '99+' : inboxUnread}
                        </span>
                    )}
                </span>
            </span>
        </Link>
    );
}

function SidebarSection({ section, collapsed, onClose, canViewPlans }) {
    return (
        <div className="space-y-0.5">
            {!collapsed && (
                <p
                    className="flex items-center justify-between px-3 pb-1.5 pt-4 text-[10px] font-bold uppercase tracking-wider"
                    style={{ color: 'var(--sidebar-text)', opacity: 0.55 }}
                >
                    <span>{section.label}</span>
                </p>
            )}
            {section.items.length === 0 && !collapsed && (
                <div
                    className="rounded-lg px-3 py-2 text-xs leading-relaxed"
                    style={{ color: 'var(--sidebar-text)', opacity: 0.6 }}
                >
                    <span>Locked by your plan.</span>{' '}
                    {canViewPlans ? (
                        <Link to="/subscription" className="underline transition-colors hover:opacity-80">
                            View plans
                        </Link>
                    ) : (
                        'An admin can change it.'
                    )}
                </div>
            )}
            {section.items.map((item) => (
                <SidebarLink key={item.to} item={item} collapsed={collapsed} onClose={onClose} />
            ))}
        </div>
    );
}

export default function Sidebar({ open, collapsed, onClose, onToggleCollapse }) {
    const { check, user } = useAuth();
    const { pathname } = useLocation();
    const isSuperAdmin = user?.is_super_admin && !user?.impersonating;

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
                className={`fixed inset-y-0 left-0 z-40 flex flex-col transition-all duration-300 lg:translate-x-0 ${
                    open ? 'translate-x-0' : '-translate-x-full'
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
                            <p className="truncate text-base font-semibold" style={{ color: 'var(--header-bg)' }}>
                                FlowSync
                            </p>
                            <p className="truncate text-xs" style={{ color: 'var(--sidebar-text)' }}>
                                {isSuperAdmin ? 'Super Admin Panel' : user?.tenant?.name || 'Admin Panel'}
                            </p>
                        </div>
                    )}
                </div>

                <nav className="flex-1 space-y-4 overflow-y-auto px-3 py-2">
                    {items.map((section) => (
                        <SidebarSection
                            key={section.label}
                            section={section}
                            collapsed={collapsed}
                            onClose={onClose}
                            canViewPlans={check('billing.view')}
                        />
                    ))}
                </nav>

                <div className="border-t px-3 py-3" style={{ borderColor: 'var(--sidebar-hover)' }}>
                    <button
                        type="button"
                        onClick={onToggleCollapse}
                        title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        className={`hidden w-full items-center rounded-lg py-2 text-sm font-medium transition-colors hover:bg-[var(--sidebar-hover)] lg:flex ${
                            collapsed ? 'justify-center' : 'gap-2 px-3'
                        }`}
                        style={{ color: 'var(--sidebar-text)' }}
                    >
                        <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            {collapsed ? (
                                <path d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                            ) : (
                                <path d="M11 5l-7 7 7 7M19 5l-7 7 7 7" />
                            )}
                        </svg>
                        {!collapsed && <span>Collapse</span>}
                    </button>
                </div>
            </aside>
        </>
    );
}