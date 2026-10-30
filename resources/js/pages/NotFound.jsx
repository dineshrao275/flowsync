import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../context/AuthContext';
import usePageTitle from '../hooks/usePageTitle';
import { homeRouteFor } from '../utils/deepLinks';

export default function NotFound() {
    const { t } = useTranslation();
    const { user } = useAuth();
    usePageTitle(t('admin.notFound.pageTitle'));
    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 px-4">
            <div className="max-w-md animate-fade-in-up text-center">
                <p className="text-7xl font-black text-slate-300">404</p>
                <h1 className="mt-4 text-2xl font-bold text-gray-900">{t('admin.notFound.heading')}</h1>
                <p className="mt-2 text-sm text-gray-500">
                    {t('admin.notFound.body')}
                </p>
                <Link
                    to={homeRouteFor(user)}
                    className="mt-6 inline-block rounded-lg bg-[var(--accent)] px-4 py-2.5 text-sm font-medium text-[var(--accent-contrast)] transition hover:bg-[var(--accent-hover)]"
                >
                    {t('admin.common.backToHome')}
                </Link>
            </div>
        </div>
    );
}