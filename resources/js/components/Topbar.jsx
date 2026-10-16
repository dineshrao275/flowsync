import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Check, Globe, List, MagnifyingGlass, Palette } from '@phosphor-icons/react';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { usePageTitleContext } from '../context/PageTitleContext';
import { useClickOutside } from '../hooks/useClickOutside';
import useLocale from '../hooks/useLocale';
import useLocales from '../hooks/useLocales';
import useMenuNavigation from '../hooks/useMenuNavigation';
import NotificationBell from './NotificationBell';
import ProductSwitcher from './ProductSwitcher';
import Avatar from './ui/Avatar';

function LanguageMenu() {
    const { t } = useTranslation();
    const { locale, setLocale } = useLocale();
    const { locales } = useLocales();
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);
    const buttonRef = useRef(null);

    useClickOutside(containerRef, () => setOpen(false));
    useMenuNavigation(open, containerRef, () => setOpen(false));

    useEffect(() => {
        if (!open) return undefined;

        function onKey(e) {
            if (e.key === 'Escape') {
                setOpen(false);
                buttonRef.current?.focus();
            }
        }

        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open ]);

    const entries = Object.entries(locales);

    if (entries.length < 2) return null;

    return (
        <div className="relative" ref={containerRef}>
            <button
                ref={buttonRef}
                onClick={() => setOpen((value) => !value)}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={t('topbar.language')}
                title={t('topbar.language')}
                className="rounded-lg p-2 transition-all duration-150 hover:bg-black/5 active:scale-90"
                style={{ color: 'var(--header-text)' }}
            >
                <Globe size={20} aria-hidden="true" />
            </button>

            {open && (
                <div role="menu" aria-label={t('topbar.language')} className="absolute right-0 z-30 mt-2 w-48 origin-top-right animate-scale-in overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg">
                    {entries.map(([code, meta]) => (
                        <button
                            key={code}
                            type="button"
                            role="menuitemradio"
                            aria-checked={locale === code}
                            onClick={() => {
                                setLocale(code);
                                setOpen(false);
                            }}
                            className={`flex w-full items-center justify-between gap-2 px-4 py-2.5 text-sm transition ${
                                locale === code ? 'bg-[var(--accent-soft)] font-medium text-[var(--accent-soft-text)]' : 'text-gray-700 hover:bg-gray-50'
                            }`}
                        >
                            <span className="truncate">{meta.native ?? meta.name ?? code}</span>
                            {locale === code && <Check size={16} weight="bold" aria-hidden="true" className="shrink-0" />}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Topbar({ onOpenTheme, onToggleSidebar, onOpenSearch, showSearch, showTheme = true }) {
    const { user, logout } = useAuth();
    const { t } = useTranslation();
    const toast = useToast();
    const navigate = useNavigate();
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef(null);
    const menuButtonRef = useRef(null);

    useClickOutside(menuRef, () => setMenuOpen(false));
    useMenuNavigation(menuOpen, menuRef, () => setMenuOpen(false));

    // Escape closes the account menu and returns focus to its trigger, so
    // keyboard users don't lose their place in the topbar.
    useEffect(() => {
        if (!menuOpen) return undefined;

        function onKey(e) {
            if (e.key === 'Escape') {
                setMenuOpen(false);
                menuButtonRef.current?.focus();
            }
        }

        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [menuOpen]);

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
        toast.info(t('topbar.signedOut'));
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
                    aria-label={t('topbar.toggleSidebar')}
                >
                    <List size={24} aria-hidden="true" />
                </button>
                <h1 className="text-lg font-semibold">{title}</h1>
            </div>

            <div className="flex items-center gap-2 sm:gap-3">
                <ProductSwitcher />
                {showSearch && (
                    <button
                        onClick={onOpenSearch}
                        className="hidden items-center gap-2 rounded-lg border border-line-input bg-white py-2 pl-3 pr-2 text-sm text-fg-muted shadow-sm transition hover:bg-gray-50 hover:text-fg sm:flex sm:w-56"
                        aria-label={t('topbar.searchLabel')}
                    >
                        <MagnifyingGlass size={16} weight="bold" aria-hidden="true" className="shrink-0" />
                        <span className="flex-1 text-left">{t('topbar.searchPlaceholder')}</span>
                        <kbd className="rounded border border-line bg-surface-sunken px-1.5 py-0.5 text-caption font-medium text-fg-subtle">
                            ⌘K
                        </kbd>
                    </button>
                )}
                {showSearch && (
                    <button
                        onClick={onOpenSearch}
                        className="rounded-lg p-2 transition-all duration-150 hover:bg-black/5 active:scale-90 sm:hidden"
                        aria-label={t('topbar.searchLabel')}
                    >
                        <MagnifyingGlass size={20} aria-hidden="true" />
                    </button>
                )}
                <NotificationBell />
                {showTheme && (
                    <button
                        onClick={onOpenTheme}
                        className="rounded-lg p-2 transition-all duration-150 hover:rotate-12 hover:bg-black/5 active:scale-90"
                        title={t('topbar.themeSettings')}
                        aria-label={t('topbar.themeSettings')}
                        style={{ color: 'var(--header-text)' }}
                    >
                        <Palette size={20} aria-hidden="true" />
                    </button>
                )}
                <LanguageMenu />

                <div className="relative" ref={menuRef}>
                    <button
                        ref={menuButtonRef}
                        onClick={() => setMenuOpen((open) => !open)}
                        aria-haspopup="menu"
                        aria-expanded={menuOpen}
                        aria-label={t('topbar.accountMenu')}
                        className="flex items-center gap-2 rounded-full p-1 transition-all duration-150 hover:bg-black/5 active:scale-95"
                    >
                        <Avatar name={user?.name} />
                    </button>

                    {menuOpen && (
                        <div role="menu" className="absolute right-0 mt-2 w-56 origin-top-right animate-scale-in overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg">
                            <div className="border-b border-gray-100 px-4 py-3">
                                <p className="truncate text-sm font-medium text-gray-900">{user?.name}</p>
                                <p className="truncate text-xs text-gray-500">{user?.email}</p>
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {(user?.roles || []).map((role) => (
                                        <span key={role} className="rounded bg-gray-100 px-1.5 py-0.5 text-caption uppercase tracking-wide text-gray-600">
                                            {role}
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <button
                                onClick={() => navigate('/settings')}
                                role="menuitem"
                                className="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-gray-700 transition hover:bg-gray-50"
                            >
                                {t('topbar.accountSettings')}
                            </button>
                            <button
                                onClick={handleLogout}
                                role="menuitem"
                                className="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-red-600 transition hover:bg-red-50"
                            >
                                {t('topbar.signOut')}
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}