import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { usePageTitleContext } from '../context/PageTitleContext';
import { useClickOutside } from '../hooks/useClickOutside';
import NotificationBell from './NotificationBell';
import Avatar from './ui/Avatar';

import AppSwitcher from './ui/AppSwitcher';

export default function Topbar({ onOpenTheme, onToggleSidebar, onOpenSearch, showSearch, showTheme = true }) {
    const { user, logout } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef(null);

    useClickOutside(menuRef, () => setMenuOpen(false));

    useEffect(() => {
        if (!showSearch) return undefined;
        function onKey(e) {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                onOpenSearch();
            }
        }
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [showSearch, onOpenSearch]);

    const { title } = usePageTitleContext();

    async function handleLogout() {
        await logout();
        toast.info('You have been signed out.');
        navigate('/login');
    }

    const primaryRole = (user?.roles && user.roles[0]) || (user?.is_super_admin ? 'Super Admin' : 'Workspace admin');

    return (
        <header
            className={`sticky z-20 flex h-[68px] items-center justify-between border-b px-4 sm:px-6 ${
                user?.impersonating ? 'top-10' : 'top-0'
            }`}
            style={{ backgroundColor: 'var(--header-bg)', borderColor: 'var(--border-hairline)', color: 'var(--header-text)' }}
        >
            <div className="flex items-center gap-3">
                <button
                    onClick={onToggleSidebar}
                    className="rounded-lg p-2 transition-all duration-150 hover:bg-black/5 active:scale-90 lg:hidden"
                    aria-label="Toggle sidebar"
                >
                    <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                        <path d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                {/* Global Search input matching Figma Search Field/Global.png */}
                {showSearch ? (
                    <button
                        onClick={onOpenSearch}
                        className="flex h-10 items-center gap-2.5 rounded-[10px] border border-[var(--border-hairline)] bg-[var(--field-bg)] px-3.5 text-[13px] text-muted transition-colors hover:border-[var(--accent)]/40 hover:bg-[var(--card-bg)] sm:w-80 md:w-96 text-left"
                        aria-label="Global search"
                    >
                        <svg className="h-4 w-4 shrink-0 text-[var(--text-faint)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>
                        <span className="flex-1 text-[13px] font-normal">Search people, projects, tasks...</span>
                        <kbd className="hidden rounded-[6px] border border-[var(--border-hairline)] bg-[var(--card-bg)] px-1.5 py-0.5 text-[10px] font-semibold text-muted sm:inline-block">
                            ⌘K
                        </kbd>
                    </button>
                ) : (
                    <h1 className="text-[16px] font-semibold text-ink">{title}</h1>
                )}
            </div>

            <div className="flex items-center gap-2 sm:gap-4">
                {/* Connected Workspace Switcher */}
                <AppSwitcher />

                <NotificationBell />

                {showTheme && (
                    <button
                        onClick={onOpenTheme}
                        className="rounded-lg p-2 transition-all duration-150 hover:bg-black/5 dark:hover:bg-white/10 active:scale-90"
                        title="Theme settings"
                        style={{ color: 'var(--header-text)' }}
                    >
                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M7.5 21a3.5 3.5 0 01-3.5-3.5c0-1.1.5-2.1 1.3-2.7l4.7-4.1a5 5 0 104-5.6L16.3 9l-.4 7.8c-.3 1.4-1.6 2.3-2.9 2.3l-3.7-1.2-.1 1.1c0 1-.8 1.9-1.7 2z" opacity=".9" />
                        </svg>
                    </button>
                )}

                {/* User Profile matching Figma Topbar */}
                <div className="relative" ref={menuRef}>
                    <button
                        onClick={() => setMenuOpen((open) => !open)}
                        className="flex items-center gap-2.5 rounded-xl p-1 transition-all duration-150 hover:bg-black/5 dark:hover:bg-white/10 active:scale-95"
                    >
                        <Avatar name={user?.name || 'Alex Rivera'} className="h-8 w-8 text-xs font-semibold" />
                        <div className="hidden text-left sm:block">
                            <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                {user?.name || 'Alex Rivera'}
                            </div>
                            <div className="text-[10px] text-[#64748b] dark:text-[#94a3b8] capitalize">
                                {primaryRole}
                            </div>
                        </div>
                    </button>

                    {menuOpen && (
                        <div className="absolute right-0 mt-2 w-56 origin-top-right animate-scale-in overflow-hidden rounded-xl border border-[var(--border-hairline)] dark:border-[#2F3A4C] bg-[var(--card-bg)] dark:bg-[#182030] shadow-popover">
                            <div className="border-b border-[var(--border-hairline)] dark:border-[#2F3A4C] px-4 py-3">
                                <p className="truncate text-sm font-medium text-[#1C1917] dark:text-[#F8FAFC]">{user?.name}</p>
                                <p className="truncate text-xs text-[#78716C] dark:text-[#94A3B8]">{user?.email}</p>
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {(user?.roles || []).map((role) => (
                                        <span key={role} className="rounded bg-gray-100 dark:bg-[#252E3E] px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-600 dark:text-[#CBD5E1]">
                                            {role}
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <button
                                onClick={() => navigate('/settings')}
                                className="flex w-full items-center gap-2 px-4 py-2.5 text-sm font-medium text-[#1C1917] dark:text-[#F8FAFC] transition hover:bg-[var(--surface-elevated)] dark:hover:bg-[#232B3A]"
                            >
                                Account settings
                            </button>
                            <button
                                onClick={handleLogout}
                                className="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-red-600 dark:text-red-500 transition hover:bg-red-50 dark:hover:bg-red-950/30"
                            >
                                Sign out
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}