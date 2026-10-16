import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { CheckCircle, Circle } from '@phosphor-icons/react';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import Card from '../ui/Card';
import Icon from '../ui/Icon';

/**
 * First-run guidance for HR admins: what a fresh workspace still needs
 * (people, an org chart, a holiday calendar, leave policies, a salary
 * template) with live done/not-done state and a link to each fix.
 *
 * Every step is probed with a read the caller is allowed to make and is
 * silently left out when the module is off, the permission is missing or the
 * probe fails — a checklist item the caller cannot act on is noise. The card
 * disappears once everything is done.
 */
const STEPS = [
    {
        key: 'employees',
        to: '/hrms/employees',
        capabilities: ['module:hrms.core', 'permission:hrms.employees.manage'],
        probe: () => api.get('/hrms/employees', { params: { per_page: 1 } }).then(({ data }) => (data.pagination?.total ?? 0) > 1),
    },
    {
        key: 'org',
        to: '/hrms/org',
        capabilities: ['module:hrms.core', 'permission:hrms.org.manage'],
        // Starter departments ship empty: "done" means at least one has people in it.
        probe: () => api.get('/hrms/org').then(({ data }) => (data.departments ?? []).some((department) => (department.direct_count ?? department.headcount ?? 0) > 0)),
    },
    {
        key: 'holidays',
        to: '/hrms/holidays',
        capabilities: ['module:hrms.core', 'module:hrms.holidays', 'permission:hrms.holidays.manage'],
        probe: () => api.get('/hrms/holidays/calendars').then(({ data }) => (data.calendars ?? []).some((calendar) => (calendar.holidays_count ?? 0) > 0)),
    },
    {
        key: 'leave',
        to: '/hrms/leave',
        capabilities: ['module:hrms.core', 'module:hrms.leave', 'permission:hrms.leave.manage'],
        probe: () => api.get('/hrms/leave/policies').then(({ data }) => (data.leave_policies ?? []).length > 0),
    },
    {
        key: 'payroll',
        to: '/hrms/compensation',
        capabilities: ['module:hrms.core', 'module:hrms.compensation', 'permission:hrms.compensation.manage'],
        probe: () => api.get('/hrms/payroll/structures').then(({ data }) => (data.structures ?? []).length > 0),
    },
];

export default function SetupChecklist() {
    const { t } = useTranslation();
    const { check } = useAuth();
    const [results, setResults] = useState(null);

    useEffect(() => {
        let active = true;
        const allowed = STEPS.filter((step) => step.capabilities.every((capability) => check(capability)));

        Promise.all(allowed.map((step) => step.probe().then((done) => ({ step, done })).catch(() => null)))
            .then((rows) => active && setResults(rows.filter(Boolean)));

        return () => { active = false; };
        // `check` is stable per auth state; probe once per mount.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (!results || results.length === 0) return null;

    const done = results.filter((row) => row.done).length;
    if (done === results.length) return null;

    return (
        <Card title={t('hrms.setup.title')} subtitle={t('hrms.setup.subtitle', { done, total: results.length })}>
            <div
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={results.length}
                aria-valuenow={done}
                aria-label={t('hrms.setup.title')}
                className="mb-4 h-2 overflow-hidden rounded-full bg-surface-sunken"
            >
                <div className="h-full rounded-full bg-[var(--accent)] transition-[width]" style={{ width: `${(done / results.length) * 100}%` }} />
            </div>
            <ul className="divide-y divide-line">
                {results.map(({ step, done: complete }) => (
                    <li key={step.key} className="flex items-center gap-3 py-3">
                        <Icon as={complete ? CheckCircle : Circle} weight={complete ? 'fill' : 'regular'} size="lg" className={complete ? 'text-success' : 'text-fg-subtle'} />
                        <div className="min-w-0 flex-1">
                            <p className={`text-sm font-medium ${complete ? 'text-fg-muted line-through' : 'text-fg'}`}>{t(`hrms.setup.steps.${step.key}.title`)}</p>
                            {!complete && <p className="text-caption text-fg-muted">{t(`hrms.setup.steps.${step.key}.hint`)}</p>}
                        </div>
                        {!complete && (
                            <Link to={step.to} className="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-[var(--accent-soft-text)] hover:bg-[var(--accent-soft)]">
                                {t('hrms.setup.open')}
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </Card>
    );
}
