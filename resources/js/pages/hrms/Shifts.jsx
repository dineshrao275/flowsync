import { useEffect, useState } from 'react';
import { useAuth } from '../../context/AuthContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import ShiftCatalog from '../../components/hrms/ShiftCatalog';
import RosterPanel from '../../components/hrms/RosterPanel';
import RotationPanel from '../../components/hrms/RotationPanel';

const TABS = [
    { key: 'roster', label: 'Roster' },
    { key: 'catalogue', label: 'Shifts', needsView: true },
    { key: 'rotations', label: 'Rotations', needsView: true },
];

/**
 * Shifts & rosters hub. The route needs the `hrms.shifts` module; the catalogue
 * and rotation tabs need `hrms.shifts.view` (everyone else lands on their own
 * roster rows), and every mutation hides without `hrms.shifts.manage`.
 */
export default function Shifts() {
    usePageTitle('Shifts');
    const setCrumbs = useSetCrumbs();
    const { can } = useAuth();
    const [tab, setTab] = useState('roster');
    const [shifts, setShifts] = useState([]);

    const canManage = can('permission:hrms.shifts.manage');
    const canView = canManage || can('permission:hrms.shifts.view');
    const tabs = TABS.filter((t) => !t.needsView || canView);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Shifts' }]);
    }, [setCrumbs]);

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">Shifts &amp; rosters</h2>
                <p className="mt-0.5 text-sm text-gray-500">Working-hours patterns, who works them, and rotating cycles.</p>
            </div>

            <div className="flex gap-1 border-b border-gray-200">
                {tabs.map((t) => (
                    <button
                        key={t.key}
                        type="button"
                        onClick={() => setTab(t.key)}
                        className={`px-3 py-2 text-sm font-medium ${tab === t.key ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500 hover:text-gray-700'}`}
                    >
                        {t.label}
                    </button>
                ))}
            </div>

            {/* The catalogue stays mounted so the other tabs always have the shift list. */}
            <div className={tab === 'catalogue' ? '' : 'hidden'}>
                {canView && <ShiftCatalog canManage={canManage} onChanged={setShifts} />}
            </div>
            {tab === 'roster' && <RosterPanel shifts={shifts} canManage={canManage} canView={canView} />}
            {tab === 'rotations' && <RotationPanel shifts={shifts} canManage={canManage} />}
        </div>
    );
}
