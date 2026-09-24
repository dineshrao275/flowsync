import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { usePageTitleContext } from '../context/PageTitleContext';
import { useClickOutside } from '../hooks/useClickOutside';
import NotificationBell from './NotificationBell';
import Avatar from './ui/Avatar';

export default function Topbar({ onOpenTheme, onToggleSidebar, onOpenSearch, showSearch }) {
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

    return (
        <header
            className={`sticky z-20 flex h-16 items-center justify-between border-b px-4 sm:px-6 ${
                user?.impersonating ? 'top-10' : 'top-0'
            }`}
            style={{ backgroundColor: 'var(--header-bg)', borderColor: 'rgba(0,0,0,0.06)', color: 'var(--header-text)' }}
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
                <h1 className="text-lg font-semibold">{title}</h1>
            </div>

            <div className="flex items-center gap-2 sm:gap-3">
                {showSearch && (
                    <button
                        onClick={onOpenSearch}
                        className="hidden items-center gap-2 rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-2 text-sm text-gray-500 shadow-sm transition hover:bg-gray-50 hover:text-gray-700 sm:flex sm:w-56"
                        aria-label="Global search"
                    >
                        <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                        </svg>
                        <span className="flex-1 text-left">Search…</span>
                        <kbd className="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-[10px] font-medium text-gray-400">
                            ⌘K
                        </kbd>
                    </button>
                )}
                {showSearch && (
                    <button
                        onClick={onOpenSearch}
                        className="rounded-lg p-2 transition-all duration-150 hover:bg-black/5 active:scale-90 sm:hidden"
                        aria-label="Global search"
                    >
                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                        </svg>
                    </button>
                )}
                <NotificationBell />
                <button
                    onClick={onOpenTheme}
                    className="rounded-lg p-2 transition-all duration-150 hover:rotate-12 hover:bg-black/5 active:scale-90"
                    title="Theme settings"
                    style={{ color: 'var(--header-text)' }}
                >
                    <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M7.5 21a3.5 3.5 0 01-3.5-3.5c0-1.1.5-2.1 1.3-2.7l4.7-4.1a5 5 0 104-5.6L16.3 9l-.4 7.8c-.3 1.4-1.6 2.3-2.9 2.3l-3.7-1.2-.1 1.1c0 1-.8 1.9-1.7 2z" opacity=".9" />
                    </svg>
                </button>

                <div className="relative" ref={menuRef}>
                    <button
                        onClick={() => setMenuOpen((open) => !open)}
                        className="flex items-center gap-2 rounded-full p-1 transition-all duration-150 hover:bg-black/5 active:scale-95"
                    >
                        <Avatar name={user?.name} />
                    </button>

                    {menuOpen && (
                        <div className="absolute right-0 mt-2 w-56 origin-top-right animate-scale-in overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg">
                            <div className="border-b border-gray-100 px-4 py-3">
                                <p className="truncate text-sm font-medium text-gray-900">{user?.name}</p>
                                <p className="truncate text-xs text-gray-500">{user?.email}</p>
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {(user?.roles || []).map((role) => (
                                        <span key={role} className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-600">
                                            {role}
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <button
                                onClick={() => navigate('/settings')}
                                className="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-gray-700 transition hover:bg-gray-50"
                            >
                                Account settings
                            </button>
                            <button
                                onClick={handleLogout}
                                className="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-red-600 transition hover:bg-red-50"
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