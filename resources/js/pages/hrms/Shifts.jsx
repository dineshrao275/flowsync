import { useEffect } from 'react';
import { useAuth } from '../../context/AuthContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import ShiftCatalog from '../../components/hrms/ShiftCatalog';

/**
 * Shifts & rosters hub. The route needs the `hrms.shifts` module; reads ride
 * the module, every mutation hides without `hrms.shifts.manage`.
 */
export default function Shifts() {
    usePageTitle('Shifts');
    const setCrumbs = useSetCrumbs();
    const { can } = useAuth();

    const canManage = can('permission:hrms.shifts.manage');

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Shifts' }]);
    }, [setCrumbs]);

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">Shifts</h2>
                <p className="mt-0.5 text-sm text-gray-500">Working-hours patterns that attendance is measured against.</p>
            </div>

            <ShiftCatalog canManage={canManage} />
        </div>
    );
}
