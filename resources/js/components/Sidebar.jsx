import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const sections = [
    {
        label: 'Main',
        items: [
            { to: '/dashboard', label: 'Dashboard', permission: 'dashboard.view', icon: 'M3 12l9-9 9 9M5 10v10h14V10' },
            { to: '/workspaces', label: 'Workspaces', permission: 'workspaces.view', icon: 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z' },
            { to: '/projects', label: 'Projects', permission: 'workspaces.view', icon: 'M2 4h20v16H2V4zm2 2v2h16V6H4zm0 6h16v-2H4v2zm0 4h16v-2H4v2z' },
            { to: '/search', label: 'Search', permission: 'workspaces.view', icon: 'M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z' },
        ],
    },
    {
        label: 'Insights',
        items: [
            { to: '/reports', label: 'Reports', permission: 'reports.view', icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' },
        ],
    },
    {
        label: 'Administration',
        items: [
            { to: '/users', label: 'Users', permission: 'users.view', icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m4-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 2a3 3 0 10-3-3' },
            { to: '/roles', label: 'Roles', permission: 'roles.view', icon: 'M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4zm-1 10l-2-2-1 1 3 3 5-5-1-1-4 4z' },
            { to: '/settings', label: 'Settings', permission: 'settings.view', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 001.065-2.572c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' },
        ],
    },
];

const superAdminSections = [
    {
        label: 'Administration',
        items: [
            { to: '/tenants', label: 'Tenants', permission: 'dashboard.view', icon: 'M3 20h18M6 8V6a3 3 0 013-3h6a3 3 0 013 3v2m-12 0h12a3 3 0 013 3v5a3 3 0 01-3 3H9a3 3 0 01-3-3v-5a3 3 0 013-3z' },
        ],
    },
];

function Icon({ path, className = '' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d={path} />
        </svg>
    );
}

function SidebarLink({ item, collapsed, onClose }) {
    const location = useLocation();
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
            <span className={`min-w-0 ${labelClass}`}>{item.label}</span>
        </Link>
    );
}

function SidebarSection({ section, collapsed, onClose }) {
    return (
        <div className="space-y-0.5">
            {!collapsed && (
                <p
                    className="px-3 pb-1.5 pt-4 text-[10px] font-bold uppercase tracking-wider"
                    style={{ color: 'var(--sidebar-text)', opacity: 0.55 }}
                >
                    {section.label}
                </p>
            )}
            {section.items.map((item) => (
                <SidebarLink key={item.to} item={item} collapsed={collapsed} onClose={onClose} />
            ))}
        </div>
    );
}

export default function Sidebar({ open, collapsed, onClose, onToggleCollapse }) {
    const { can, user } = useAuth();
    const isSuperAdmin = user?.is_super_admin && !user?.impersonating;

    const items = (isSuperAdmin ? superAdminSections : sections)
        .map((section) => ({ ...section, items: section.items.filter((item) => can(item.permission)) }))
        .filter((section) => section.items.length > 0);

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
                        <SidebarSection key={section.label} section={section} collapsed={collapsed} onClose={onClose} />
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