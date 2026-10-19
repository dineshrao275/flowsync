import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../context/AuthContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import ShiftCatalog from '../../components/hrms/ShiftCatalog';
import RosterPanel from '../../components/hrms/RosterPanel';
import RotationPanel from '../../components/hrms/RotationPanel';

const TABS = [
    { key: 'roster', labelKey: 'hrms.shifts.tabRoster' },
    { key: 'catalogue', labelKey: 'hrms.shifts.tabCatalogue', needsView: true },
    { key: 'rotations', labelKey: 'hrms.shifts.tabRotations', needsView: true },
];

/**
 * Shifts & rosters hub. The route needs the `hrms.shifts` module; the catalogue
 * and rotation tabs need `hrms.shifts.view` (everyone else lands on their own
 * roster rows), and every mutation hides without `hrms.shifts.manage`.
 */
export default function Shifts() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.shifts.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const { can } = useAuth();
    const [tab, setTab] = useState('roster');
    const [shifts, setShifts] = useState([]);

    const canManage = can('permission:hrms.shifts.manage');
    const canView = canManage || can('permission:hrms.shifts.view');
    const tabs = TABS.filter((t) => !t.needsView || canView);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.shifts.pageTitle') }]);
    }, [setCrumbs, t]);

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.shifts.heading')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">{t('hrms.shifts.subtitle')}</p>
            </div>

            <div className="flex gap-1 border-b border-gray-200">
                {tabs.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => setTab(item.key)}
                        className={`px-3 py-2 text-sm font-medium ${tab === item.key ? 'border-b-2 border-[var(--accent)] text-[var(--accent-soft-text)]' : 'text-gray-500 hover:text-gray-700'}`}
                    >
                        {t(item.labelKey)}
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
