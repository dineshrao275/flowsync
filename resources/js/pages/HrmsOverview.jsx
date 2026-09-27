import { Link } from 'react-router-dom';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import EmptyState from '../components/ui/EmptyState';
import { useAuth } from '../context/AuthContext';
import { hrmsModuleGroups } from '../utils/hrmsModules';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

/**
 * HRMS landing page.
 *
 * Renders exactly the modules the tenant's plan grants — `user.modules` is
 * resolved server-side by `TenantLimits`, so this page never has to know which
 * plan a tenant is on, and a module that is switched off is simply absent
 * rather than disabled.
 *
 * The gated modules point at their route via `HRMS_MODULE_ROUTES`. Sections
 * whose phase has not landed yet render as inert tiles: linking to a route the
 * router does not know would land the user on a blank screen, which reads as a
 * bug rather than as "not built yet".
 */
export default function HrmsOverview() {
    const { user } = useAuth();
    usePageTitle('HRMS');
    useSetCrumbs([{ label: 'HRMS' }]);

    const modules = user?.modules ?? [];
    const groups = hrmsModuleGroups(modules);

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-gray-900">Human Resources</h1>
                <p className="mt-1 text-sm text-gray-500">
                    {modules.length} module{modules.length === 1 ? '' : 's'} enabled on your plan.
                </p>
            </div>

            {groups.length === 0 ? (
                <Card>
                    <EmptyState
                        title="No HRMS modules enabled"
                        description="Your plan does not include any HRMS modules. Contact an administrator to upgrade."
                    />
                </Card>
            ) : (
                groups.map(({ group, modules: items }) => (
                    <div key={group}>
                        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            {group.replace(/^HRMS · /, '')}
                        </h2>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {items.map((item) => (
                                <ModuleTile key={item.key} item={item} />
                            ))}
                        </div>
                    </div>
                ))
            )}
        </div>
    );
}

function ModuleTile({ item }) {
    const body = (
        <>
            <div className="flex items-start justify-between gap-3">
                <span className="font-medium text-gray-900">{item.label}</span>
                {!item.to && <Badge>Planned</Badge>}
            </div>
            <p className="mt-1 font-mono text-xs text-gray-400">{item.key}</p>
        </>
    );

    if (!item.to) {
        return (
            <div
                className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 p-5 text-left"
                style={{ backgroundColor: 'var(--card-bg)' }}
            >
                {body}
            </div>
        );
    }

    return (
        <Link
            to={item.to}
            className="rounded-xl border border-gray-200/70 bg-white p-5 text-left shadow-card transition-shadow duration-200 hover:shadow-popover"
            style={{ backgroundColor: 'var(--card-bg)' }}
        >
            {body}
        </Link>
    );
}
