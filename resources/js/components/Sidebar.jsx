import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useNotifications } from '../context/NotificationContext';

const tmsSections = [
    {
        label: 'TMS WORKSPACE',
        tag: 'TMS WORKSPACE',
        items: [
            { to: '/dashboard', label: 'Overview', capabilities: ['dashboard.view', 'product:tms'] },
            { to: '/projects', label: 'Projects & boards', capabilities: ['workspaces.view', 'product:tms'] },
            { to: '/my', label: 'My tasks', capabilities: ['module:hrms.core'] },
            { to: '/timeline', label: 'Timeline & roadmap', capabilities: ['workspaces.view', 'product:tms'] },
            { to: '/time-tracking', label: 'Time tracking', capabilities: ['workspaces.view', 'product:tms'] },
            { to: '/reports', label: 'Reports', capabilities: ['reports.view', 'product:tms'] },
            { to: '/webhooks', label: 'Workflow & automation', capabilities: ['webhooks.manage', 'module:webhooks'] },
            { to: '/users', label: 'Team', capabilities: ['users.view'] },
            { to: '/settings', label: 'Integrations', capabilities: ['settings.view'] },
            { to: '/settings', label: 'Settings', capabilities: ['settings.view'] },
        ],
    },
];

const hrmsSections = [
    {
        label: 'HRMS WORKSPACE',
        tag: 'HRMS WORKSPACE',
        items: [
            { to: '/hrms', label: 'Overview', capabilities: ['module:hrms.core'] },
            { to: '/hrms/employees', label: 'Employees', capabilities: ['hrms.employees.view'] },
            { to: '/hrms/org', label: 'Organization', capabilities: ['hrms.org.view'] },
            { to: '/hrms/onboarding', label: 'Onboarding', capabilities: ['hrms.onboarding.view'] },
            { to: '/hrms/attendance', label: 'Attendance', capabilities: ['module:hrms.core'] },
            { to: '/hrms/leave', label: 'Leave & approvals', capabilities: ['module:hrms.core'], badge: 'inbox' },
            { to: '/hrms/payroll', label: 'Payroll', capabilities: ['module:hrms.core'] },
            { to: '/hrms/performance', label: 'Performance', capabilities: ['module:hrms.core'] },
            { to: '/hrms/documents', label: 'Documents & assets', capabilities: ['hrms.documents.view'] },
            { to: '/reports', label: 'Reports', capabilities: ['reports.view'] },
            { to: '/settings', label: 'Settings', capabilities: ['settings.view'] },
        ],
    },
];

const superAdminSections = [
    {
        label: 'PLATFORM WORKSPACE',
        tag: 'PLATFORM WORKSPACE',
        items: [
            { to: '/admin', label: 'Overview', capabilities: ['dashboard.view'] },
            { to: '/tenants', label: 'Tenants', capabilities: ['dashboard.view'] },
            { to: '/plans', label: 'Plans & features', capabilities: ['dashboard.view'] },
            { to: '/subscription', label: 'Billing', capabilities: ['dashboard.view'] },
            { to: '/admin/users', label: 'Users & access', capabilities: ['dashboard.view'] },
            { to: '/admin/audit-logs', label: 'Audit log', capabilities: ['dashboard.view'] },
            { to: '/admin/analytics', label: 'Platform health', capabilities: ['dashboard.view'] },
        ],
    },
];

function SidebarLink({ item, collapsed, onClose }) {
    const location = useLocation();
    const { inboxUnread } = useNotifications();
    const isActive = location.pathname === item.to || (item.to !== '/dashboard' && item.to !== '/hrms' && location.pathname.startsWith(item.to));

    return (
        <Link
            to={item.to}
            onClick={onClose}
            role="menuitem"
            aria-current={isActive ? 'page' : undefined}
            title={collapsed ? item.label : undefined}
            className={`group relative flex items-center gap-3 rounded-[10px] px-3 py-2 text-[12px] font-medium transition-all duration-150 ${
                isActive
                    ? 'bg-[var(--sidebar-active)] text-white shadow-sm'
                    : 'text-[#8f9bb3] hover:bg-[var(--sidebar-hover)] hover:text-white'
            } ${collapsed ? 'justify-center' : ''}`}
        >
            {/* Dot icon matching Figma navigation */}
            {isActive ? (
                <span className="flex h-3.5 w-3.5 shrink-0 items-center justify-center">
                    <span className="h-2 w-2 rounded-full bg-white" />
                </span>
            ) : (
                <span className="h-3.5 w-3.5 shrink-0 rounded-full border border-[#4a5568] transition-colors group-hover:border-[#94a3b8]" />
            )}

            {!collapsed && (
                <span className="flex flex-1 items-center justify-between gap-2 truncate">
                    <span className="truncate">{item.label}</span>
                    {item.badge === 'inbox' && inboxUnread > 0 && (
                        <span className="flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-[#d94e61] px-1 text-[10px] font-bold text-white">
                            {inboxUnread > 99 ? '99+' : inboxUnread}
                        </span>
                    )}
                </span>
            )}
        </Link>
    );
}

export default function Sidebar({ open, collapsed, onClose, onToggleCollapse }) {
    const { check, user } = useAuth();
    const location = useLocation();
    const isSuperAdmin = user?.is_super_admin && !user?.impersonating;

    // Detect module context
    const isHrms = location.pathname.startsWith('/hrms') || location.pathname.startsWith('/my');
    const workspaceType = isSuperAdmin ? 'PLATFORM' : isHrms ? 'HRMS' : 'TMS';
    const workspaceTag = `${workspaceType} WORKSPACE`;

    const activeSections = isSuperAdmin
        ? superAdminSections
        : isHrms
        ? hrmsSections
        : tmsSections;

    const items = activeSections.map((section) => ({
        ...section,
        items: section.items.filter(
            (item) => !item.capabilities?.length || item.capabilities.every((cap) => check(cap)),
        ),
    }));

    const primaryRole = (user?.roles && user.roles[0]) || (isSuperAdmin ? 'Platform Admin' : 'Workspace admin');

    return (
        <>
            {open && (
                <div
                    className="fixed inset-0 z-30 animate-backdrop-in bg-stone-900/60 backdrop-blur-sm lg:hidden"
                    onClick={onClose}
                    aria-hidden="true"
                />
            )}

            <aside
                className={`fixed inset-y-0 left-0 z-40 flex flex-col transition-all duration-300 lg:translate-x-0 ${
                    open ? 'translate-x-0' : '-translate-x-full'
                } ${collapsed ? 'w-16 lg:w-16' : 'w-[228px] lg:w-[228px]'} border-r border-[#232b3e]`}
                style={{
                    backgroundColor: '#171c2c',
                    top: user?.impersonating ? '2.5rem' : '0',
                    height: user?.impersonating ? 'calc(100% - 2.5rem)' : '100%',
                }}
            >
                {/* Header: Logo & Workspace Title */}
                <div className={`relative flex shrink-0 items-center gap-3 px-4 py-4 ${collapsed ? 'flex-col justify-center gap-2 px-0' : ''}`}>
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-[#4b5ef5] text-base font-bold text-white shadow-md">
                        F
                    </div>
                    {!collapsed && (
                        <div className="min-w-0 pr-6">
                            <p className="truncate text-base font-bold tracking-tight text-white">
                                FlowSync
                            </p>
                            <p className="truncate text-[10px] font-semibold uppercase tracking-wider text-[#8f9bb3]">
                                {workspaceTag}
                            </p>
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={onToggleCollapse}
                        title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        aria-expanded={!collapsed}
                        className={`rounded p-1 text-[#8f9bb3] transition hover:bg-[#222a40] hover:text-white ${
                            collapsed ? '' : 'absolute right-3 top-1/2 -translate-y-1/2'
                        }`}
                    >
                        {collapsed ? (
                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                            </svg>
                        ) : (
                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M11 5l-7 7 7 7M19 5l-7 7 7 7" />
                            </svg>
                        )}
                    </button>
                </div>

                {/* Suite Overview Top Action */}
                <div className="px-3 pt-1 pb-2">
                    <Link
                        to="/suite"
                        onClick={onClose}
                        title={collapsed ? 'Suite overview' : undefined}
                        className={`flex items-center gap-2.5 rounded-xl px-3 py-2 text-xs font-semibold transition-all ${
                            location.pathname === '/suite'
                                ? 'bg-[#4b5ef5] text-white shadow-sm'
                                : 'text-[#8f9bb3] hover:bg-[#1a2133] hover:text-white'
                        } ${collapsed ? 'justify-center' : ''}`}
                    >
                        <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <rect x="3" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="14" width="7" height="7" rx="1" />
                            <rect x="3" y="14" width="7" height="7" rx="1" />
                        </svg>
                        {!collapsed && <span>Suite overview</span>}
                    </Link>
                </div>

                {/* Workspace Section Bar Tag */}
                {!collapsed && (
                    <div className="px-3 py-1">
                        <div className="rounded-lg bg-[#111625] px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-[#64748b]">
                            {workspaceTag}
                        </div>
                    </div>
                )}

                {/* Navigation Items */}
                <nav className="min-h-0 flex-1 space-y-1 overflow-y-auto px-3 py-1 scrollbar-thin">
                    {items.map((section) => (
                        <div key={section.label} className="space-y-0.5">
                            {section.items.map((item) => (
                                <SidebarLink key={item.label} item={item} collapsed={collapsed} onClose={onClose} />
                            ))}
                        </div>
                    ))}
                </nav>

                {/* Bottom User Profile Row matching Figma */}
                <div className="border-t border-[#232b3e] p-3">
                    <div className={`flex items-center gap-3 ${collapsed ? 'justify-center' : ''}`}>
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#4b5ef5] text-xs font-bold text-white shadow-sm">
                            {(user?.name || 'Alex Rivera')
                                .split(' ')
                                .map((n) => n[0])
                                .slice(0, 2)
                                .join('')
                                .toUpperCase()}
                        </div>
                        {!collapsed && (
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-xs font-semibold text-white">
                                    {user?.name || 'Alex Rivera'}
                                </div>
                                <div className="truncate text-[10px] text-[#8f9bb3] capitalize">
                                    {primaryRole}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </aside>
        </>
    );
}