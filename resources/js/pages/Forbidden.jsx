import { Link, useLocation } from 'react-router-dom';
import { Trans, useTranslation } from 'react-i18next';
import { useAuth } from '../context/AuthContext';
import usePageTitle from '../hooks/usePageTitle';

export default function Forbidden() {
    const { t } = useTranslation();
    usePageTitle(t('admin.forbidden.pageTitle'));
    const { user } = useAuth();
    const { state } = useLocation();
    const missing = state?.permission ?? null;

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 px-4">
            <div className="max-w-md animate-fade-in-up text-center">
                <p className="text-7xl font-black" style={{ color: 'var(--active-menu)' }}>
                    403
                </p>
                <h1 className="mt-4 text-2xl font-bold text-gray-900">{t('admin.forbidden.heading')}</h1>
                <p className="mt-2 text-sm text-gray-500">
                    {missing ? (
                        <Trans
                            i18nKey="admin.forbidden.needsPermission"
                            values={{ permission: missing }}
                            components={[<code key="0" className="rounded bg-gray-200 px-1 py-0.5" />]}
                        />
                    ) : user?.roles?.length ? (
                        <>{t('admin.forbidden.roleList', { roles: user.roles.map((r) => ` ${r}`) })}</>
                    ) : (
                        t('admin.forbidden.generic')
                    )}
                </p>
                <Link
                    to="/dashboard"
                    className="mt-6 inline-block rounded-lg bg-[var(--accent)] px-4 py-2.5 text-sm font-medium text-[var(--accent-contrast)] transition hover:bg-[var(--accent-hover)]"
                >
                    {t('admin.common.backToDashboard')}
                </Link>
            </div>
        </div>
    );
}