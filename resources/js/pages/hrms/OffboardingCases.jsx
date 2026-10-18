import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { Table, Th, Td } from '../../components/ui/Table';
import EmptyState from '../../components/ui/EmptyState';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';

const STATUSES = [
    { value: 'initiated', key: 'stInitiated' },
    { value: 'in_progress', key: 'stInProgress' },
    { value: 'completed', key: 'stCompleted' },
    { value: 'cancelled', key: 'stCancelled' },
];

const REASONS = [
    { value: 'resigned', key: 'reasonResigned' },
    { value: 'terminated', key: 'reasonTerminated' },
    { value: 'retired', key: 'reasonRetired' },
    { value: 'contract_end', key: 'reasonContractEnd' },
    { value: 'other', key: 'reasonOther' },
];

/**
 * The exit runs: who is leaving, when, and whether their clearance is
 * signed.
 *
 * The clearance column answers the morning scan in one glance — a case whose
 * clearance is blocked needs chasing, a signed one needs nothing — so each
 * row carries its blocked state rather than requiring a click into the
 * detail to learn it.
 */
export default function OffboardingCases() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.offboarding.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const [status, setStatus] = useState('');
    const [cases, setCases] = useState(null);
    const [employees, setEmployees] = useState(null);
    const [error, setError] = useState(null);
    const [opening, setOpening] = useState(false);
    const [form, setForm] = useState({ employee_id: '', last_working_day: '', reason: 'resigned', notice_period_days: '' });
    const [formErrors, setFormErrors] = useState({});

    const canManage = can('permission:hrms.offboarding.manage');
    const canPickEmployee = can('permission:hrms.employees.view');

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.offboarding.pageTitle') }]);
    }, [setCrumbs, t]);

    const load = useCallback(() => {
        setError(null);

        const params = status ? { status } : {};

        return api
            .get('/hrms/offboarding/cases', { params })
            .then(({ data }) => setCases(data.cases ?? []))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.offboarding.loadFailed'));
            });
    }, [status, navigate, t]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (canPickEmployee) {
            api.get('/hrms/employees', { params: { per_page: 100 } })
                .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
                .catch(() => setEmployees([]));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function open(e) {
        e.preventDefault();
        setOpening(true);
        setFormErrors({});

        try {
            const { data } = await api.post('/hrms/offboarding/cases', {
                employee_id: Number(form.employee_id),
                last_working_day: form.last_working_day,
                reason: form.reason,
                notice_period_days: form.notice_period_days === '' ? null : Number(form.notice_period_days),
            });

            toast.success(t('hrms.offboarding.started'));
            setForm({ employee_id: '', last_working_day: '', reason: 'resigned', notice_period_days: '' });
            navigate(`/hrms/offboarding/cases/${data.case.id}`);
        } catch (err) {
            setFormErrors(fieldErrors(err));
        } finally {
            setOpening(false);
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.offboarding.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {cases ? (cases.length === 1 ? t('hrms.offboarding.exitsOne') : t('hrms.offboarding.exits', { count: cases.length })) : '—'}
                    </p>
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            {canManage && canPickEmployee && (
                <form onSubmit={open} className="grid gap-3 rounded-xl border border-gray-200/70 bg-white p-3 sm:grid-cols-2 lg:grid-cols-5">
                    <Select label={t('hrms.offboarding.employee')} value={form.employee_id} onChange={(e) => setForm((f) => ({ ...f, employee_id: e.target.value }))}>
                        <option value="">{t('hrms.offboarding.selectEmployee')}</option>
                        {(employees ?? []).map((employee) => (
                            <option key={employee.id} value={employee.id}>
                                {employee.name}
                            </option>
                        ))}
                    </Select>
                    <Input label={t('hrms.offboarding.lastDay')} type="date" value={form.last_working_day} onChange={(e) => setForm((f) => ({ ...f, last_working_day: e.target.value }))} />
                    <Select label={t('hrms.offboarding.reason')} value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))}>
                        {REASONS.map((reason) => (
                            <option key={reason.value} value={reason.value}>
                                {t(`hrms.offboarding.${reason.key}`)}
                            </option>
                        ))}
                    </Select>
                    <Input label={t('hrms.offboarding.notice')} type="number" value={form.notice_period_days} onChange={(e) => setForm((f) => ({ ...f, notice_period_days: e.target.value }))} />
                    <div className="flex items-end">
                        <Button type="submit" loading={opening} disabled={!form.employee_id || !form.last_working_day}>
                            {t('hrms.offboarding.startCase')}
                        </Button>
                    </div>
                    {formErrors.form && <Alert>{formErrors.form}</Alert>}
                </form>
            )}

            <div className="grid gap-3 rounded-xl border border-gray-200/70 bg-white p-3 sm:grid-cols-2 lg:grid-cols-4">
                <Select label={t('hrms.offboarding.status')} value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">{t('hrms.offboarding.anyStatus')}</option>
                    {STATUSES.map((s) => (
                        <option key={s.value} value={s.value}>
                            {t(`hrms.offboarding.${s.key}`)}
                        </option>
                    ))}
                </Select>
            </div>

            {!cases ? (
                <ContentSkeleton size="md" />
            ) : cases.length === 0 ? (
                <EmptyState title={t('hrms.offboarding.emptyTitle')} description={t('hrms.offboarding.emptyHint')} />
            ) : (
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('hrms.offboarding.thEmployee')}</Th>
                            <Th>{t('hrms.offboarding.thLastDay')}</Th>
                            <Th>{t('hrms.offboarding.thReason')}</Th>
                            <Th>{t('hrms.offboarding.thStatus')}</Th>
                            <Th>{t('hrms.offboarding.thClearance')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {cases.map((row) => (
                            <tr key={row.id}>
                                <Td>
                                    <Link to={`/hrms/offboarding/cases/${row.id}`} className="font-medium text-[var(--accent-soft-text)] hover:underline">
                                        {row.employee?.name ?? '—'}
                                    </Link>
                                </Td>
                                <Td>{row.last_working_day}</Td>
                                <Td>{row.reason_label ?? row.reason}</Td>
                                <Td className="capitalize">{row.status_label ?? row.status}</Td>
                                <Td>
                                    {row.clearance?.cleared_at ? (
                                        <span className="text-sm text-green-700">{t('hrms.offboarding.clearanceSigned')}</span>
                                    ) : (row.clearance?.blocked_reasons ?? []).length > 0 ? (
                                        <span className="text-sm font-medium text-red-600">{t('hrms.offboarding.clearanceBlocked')}</span>
                                    ) : (
                                        <span className="text-sm text-gray-400">{t('hrms.offboarding.clearanceOpen')}</span>
                                    )}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </div>
    );
}
