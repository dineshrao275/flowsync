import { Link, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useNotifications } from '../../context/NotificationContext';
import { activeHrmsTab, hrmsNavGroups } from '../../utils/hrmsNav';
import HrmsErrorBoundary from './HrmsErrorBoundary';

/**
 * The HRMS hub shell: one sidebar entry (`/hrms`) with every feature as a
 * sub-tab rendered through `<Outlet/>` (Phase 3).
 *
 * The rail is the hub's secondary navigation, not a second sidebar — it is
 * nested inside the content column so the primary sidebar stays a single
 * "HR" item. Tabs are filtered by the same capabilities that gate their
 * routes (`HrmsNavTest` pins the two together), so the rail never offers a
 * tab the route will 403.
 */
export default function HrmsLayout() {
    const { check } = useAuth();
    const { inboxUnread } = useNotifications();
    const { pathname } = useLocation();

    const groups = hrmsNavGroups(check);
    const active = activeHrmsTab(pathname);
    const flat = groups.flatMap((group) => group.items);

    const linkClass = (tab) =>
        `block rounded-2xl border border-[var(--border-hairline)] dark:border-[#2F3A4C] bg-[var(--surface-elevated)] dark:bg-[#182030] px-3 py-2 text-sm font-medium transition-colors duration-150 ${
            active === tab.to ? 'bg-[var(--active-menu)] text-white' : 'text-[#57534E] dark:text-[#94A3B8] hover:bg-stone-200/60 dark:hover:bg-[#232B3A] hover:text-[#1C1917] dark:hover:text-[#F8FAFC]'
        }`;

    const label = (tab) => (
        <span className="flex min-w-0 items-center justify-between gap-2">
            <span className="truncate">{tab.label}</span>
            {tab.badge === 'inbox' && inboxUnread > 0 && (
                <span className="flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                    {inboxUnread > 99 ? '99+' : inboxUnread}
                </span>
            )}
        </span>
    );

    return (
        <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:gap-7">
            {/* Mobile: a horizontal strip — a 30-item rail inside the content
                column would leave no room for the page itself. */}
<nav
                aria-label="HRMS sections"
                className="-mx-1 flex gap-1 overflow-x-auto pb-1 lg:hidden rounded-xl border border-[var(--border-hairline)] dark:border-[#2F3A4C] bg-[var(--surface-elevated)] dark:bg-[#182030] p-1"
            >
                {flat.map((tab) => (
                    <Link
                        key={tab.to}
                        to={tab.to}
                        aria-current={active === tab.to ? 'page' : undefined}
                        className={`shrink-0 whitespace-nowrap rounded-xl px-3 py-2 text-sm font-medium ${
                            active === tab.to ? 'bg-[var(--active-menu)] text-white' : 'text-[#57534E] dark:text-[#94A3B8] hover:bg-stone-200/60 dark:hover:bg-[#232B3A] hover:text-[#1C1917] dark:hover:text-[#F8FAFC]'}
                        `}
                    >
                        {label(tab)}
                    </Link>
                ))}
            </nav>

            {/* Desktop: grouped rail, sticky so long pages keep their nav. */}
            <nav
                aria-label="HRMS sections"
                className="hidden w-52 shrink-0 space-y-5 self-start lg:sticky lg:top-24 lg:block"
            >
                {groups.map((group) => (
                    <div key={group.group} className="space-y-0.5">
                        {group.group !== 'Overview' && (
                            <p
                                className="px-3 pb-1.5 text-[10px] font-bold uppercase text-[#78716C] dark:text-[#64748B]"
                            >
                                {group.group}
                            </p>
                        )}
                        {group.items.map((tab) => (
                            <Link
                                key={tab.to}
                                to={tab.to}
                                aria-current={active === tab.to ? 'page' : undefined}
                                className={linkClass(tab)}
                            >
                                {label(tab)}
                            </Link>
                        ))}
                    </div>
                ))}
            </nav>

            <div className="min-w-0 flex-1">
                <HrmsErrorBoundary resetKey={pathname}>
                    <Outlet />
                </HrmsErrorBoundary>
            </div>
        </div>
    );
}
