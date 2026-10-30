import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../context/AuthContext';
import { useActiveProduct } from '../context/ProductContext';
import { useCrumbs } from '../context/BreadcrumbContext';

function Chevron() {
    return (
        <svg className="h-3.5 w-3.5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 18l6-6-6-6" />
        </svg>
    );
}

export default function Breadcrumbs() {
    const { t } = useTranslation();
    const { user } = useAuth();
    const { activeProduct } = useActiveProduct();
    const { crumbs } = useCrumbs();

    const isSuperAdmin = user?.is_super_admin && !user?.impersonating;
    const accountLabel = isSuperAdmin ? t('shell.superAdmin') : user?.tenant?.name || t('shell.admin');
    const accountTo = isSuperAdmin ? '/tenants' : activeProduct === 'hrms' ? '/hrms' : '/workspaces';
    const trail = [{ label: accountLabel, to: accountTo }, ...crumbs];

    return (
        <nav aria-label={t('shell.breadcrumb')} className="mb-4">
            <ol className="flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                {trail.map((crumb, index) => {
                    const last = index === trail.length - 1;
                    return (
                        <li key={`${index}-${crumb.label}`} className="flex items-center gap-1.5">
                            {index > 0 && <Chevron />}
                            {last || !crumb.to ? (
                                <span className="font-semibold text-gray-800" aria-current={last ? 'page' : undefined}>{crumb.label}</span>
                            ) : (
                                <Link
                                    to={crumb.to}
                                    className="text-gray-600 transition hover:text-gray-900"
                                >
                                    {crumb.label}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}