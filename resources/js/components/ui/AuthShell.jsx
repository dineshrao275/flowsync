import { useTranslation } from 'react-i18next';
import { getAppName } from '../../utils/appName';

export default function AuthShell({ title, subtitle, children, footer }) {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-100 via-white to-slate-100 px-4 py-10 dark:bg-gradient-to-br dark:from-[#0b1020] dark:via-[#0f1629] dark:to-[#0b1020]">
            <div className="w-full max-w-md">
                {/* A full-page link: the marketing site is server-rendered outside the SPA router. */}
                <a href="/" className="mb-6 inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 dark:text-blue-400 dark:hover:bg-[#1a2235] dark:hover:text-gray-100">
                    <span aria-hidden="true">←</span> {t('auth.backToSite')}
                </a>
                <div className="mb-8 flex animate-fade-in-up items-center justify-center gap-3">
                    <div className="flex h-10 w-10 animate-bounce-soft items-center justify-center rounded-xl bg-[var(--accent)] text-lg font-bold text-[var(--accent-contrast)] shadow-lg shadow-[var(--accent-ring)]">
                        F
                    </div>
                    <span className="text-xl font-semibold text-slate-900 dark:text-blue-100">{getAppName()}</span>
                </div>

                <div className="animate-fade-in-up rounded-2xl border border-slate-200 bg-white p-8 shadow-xl shadow-slate-200/50 dark:border-[#2a3347] dark:bg-[#151b2b] dark:shadow-none" style={{ animationDelay: '60ms' }}>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-blue-100">{title}</h1>
                    {subtitle && <p className="mt-1 text-sm text-slate-500 dark:text-blue-400">{subtitle}</p>}
                    <div className="mt-6">{children}</div>
                </div>

                {footer && <div className="mt-6 animate-fade-in text-center text-sm text-slate-500 dark:text-blue-400" style={{ animationDelay: '140ms' }}>{footer}</div>}
            </div>
        </div>
    );
}