import { Link, useLocation } from 'react-router-dom';
import { Trans, useTranslation } from 'react-i18next';
import { useAuth } from '../context/AuthContext';
import usePageTitle from '../hooks/usePageTitle';

export default function ModuleDenied() {
    const { t } = useTranslation();
    usePageTitle(t('admin.moduleDenied.pageTitle'));
    const { user, can } = useAuth();
    const location = useLocation();
    const module = location.state?.module;

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 px-4">
            <div className="max-w-md animate-fade-in-up text-center">
                <p className="text-7xl font-black" style={{ color: 'var(--active-menu)' }}>
                    403
                </p>
                <h1 className="mt-4 text-2xl font-bold text-gray-900">{t('admin.moduleDenied.heading')}</h1>
                <p className="mt-2 text-sm text-gray-500">
                    {module ? (
                        <Trans
                            i18nKey="admin.moduleDenied.withModule"
                            values={{ module }}
                            components={[<code key="0" className="rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-800" />]}
                        />
                    ) : (
                        t('admin.moduleDenied.generic')
                    )}
                    {user && !can('billing.view') && (
                        <> {t('admin.moduleDenied.adminHint')}</>
                    )}
                </p>
                <div className="mt-6 flex items-center justify-center gap-3">
                    <Link
                        to="/dashboard"
                        className="inline-block rounded-lg bg-[var(--accent)] px-4 py-2.5 text-sm font-medium text-[var(--accent-contrast)] transition hover:bg-[var(--accent-hover)]"
                    >
                        {t('admin.common.backToDashboard')}
                    </Link>
                    {can('billing.view') && (
                        <Link
                            to="/subscription"
                            className="inline-block rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                        >
                            {t('admin.common.viewPlans')}
                        </Link>
                    )}
                </div>
            </div>
        </div>
    );
}