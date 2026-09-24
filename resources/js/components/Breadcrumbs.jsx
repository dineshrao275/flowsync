import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useCrumbs } from '../context/BreadcrumbContext';

function Chevron() {
    return (
        <svg className="h-3.5 w-3.5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 18l6-6-6-6" />
        </svg>
    );
}

export default function Breadcrumbs() {
    const { user } = useAuth();
    const { crumbs } = useCrumbs();

    const isSuperAdmin = user?.is_super_admin && !user?.impersonating;
    const accountLabel = isSuperAdmin ? 'Super Admin' : user?.tenant?.name || 'Admin';
    const trail = [{ label: accountLabel, to: isSuperAdmin ? '/tenants' : '/workspaces' }, ...crumbs];

    return (
        <nav aria-label="Breadcrumb" className="mb-4">
            <ol className="flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                {trail.map((crumb, index) => {
                    const last = index === trail.length - 1;
                    return (
                        <li key={crumb.label} className="flex items-center gap-1.5">
                            {index > 0 && <Chevron />}
                            {last || !crumb.to ? (
                                <span className="font-semibold text-gray-700">{crumb.label}</span>
                            ) : (
                                <Link
                                    to={crumb.to}
                                    className="transition hover:text-indigo-600"
                                    style={{ color: 'var(--sidebar-text)' }}
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