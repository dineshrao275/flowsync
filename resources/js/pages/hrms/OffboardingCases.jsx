import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import MetricCard from '../../components/ui/MetricCard';
import StatusPill from '../../components/ui/StatusPill';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';

const WORKFLOW_STEPS = [
    { num: 1, title: 'Resignation submitted', count: '12 employees', color: '#4B5EF5' },
    { num: 2, title: 'Manager & HR review', count: '10 employees', color: '#7B61FF' },
    { num: 3, title: 'Notice period', count: '6 employees', color: '#1F9B69' },
    { num: 4, title: 'Clearance & assets', count: '4 employees', color: '#DA972E' },
    { num: 5, title: 'Final settlement', count: '3 employees', color: '#00A884' },
    { num: 6, title: 'Access revoked', count: '2 employees', color: '#E05260' },
];

const MOCK_EXITS = [
    { id: 1, initials: 'MC', name: 'Michael Chen', dateDept: 'Oct 18 · Engineering', status: 'Notice period', variant: 'warning', color: '#4B5EF5' },
    { id: 2, initials: 'AY', name: 'Aisha Yusuf', dateDept: 'Oct 22 · Design', status: 'Clearance', variant: 'warning', color: '#1F9B69' },
    { id: 3, initials: 'RK', name: 'Rahul Kumar', dateDept: 'Oct 30 · Finance', status: 'Final review', variant: 'warning', color: '#7B61FF' },
    { id: 4, initials: 'PJ', name: 'Priya Joshi', dateDept: 'Nov 02 · Product', status: 'Manager review', variant: 'warning', color: '#00A884' },
];

const REASONS = [
    { value: 'resigned', label: 'Resigned' },
    { value: 'terminated', label: 'Terminated' },
    { value: 'retired', label: 'Retired' },
    { value: 'contract_end', label: 'Contract ended' },
    { value: 'other', label: 'Other' },
];

export default function OffboardingCases() {
    usePageTitle('Offboarding & exit management');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const [cases, setCases] = useState(null);
    const [employees, setEmployees] = useState(null);
    const [error, setError] = useState(null);
    const [modal, setModal] = useState(false);
    const [opening, setOpening] = useState(false);
    const [form, setForm] = useState({ employee_id: '', last_working_day: '', reason: 'resigned', notice_period_days: '' });
    const [formErrors, setFormErrors] = useState({});

    const canManage = can('permission:hrms.offboarding.manage') || can('hrms.offboarding.manage');
    const canPickEmployee = can('permission:hrms.employees.view') || can('hrms.employees.view');

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Offboarding' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);
        return api
            .get('/hrms/offboarding/cases')
            .then(({ data }) => setCases(data.cases ?? []))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }
                setError('Unable to load exit runs.');
            });
    }, [navigate]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (canPickEmployee) {
            api.get('/hrms/employees', { params: { per_page: 100 } })
                .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
                .catch(() => setEmployees([]));
        }
    }, [canPickEmployee]);

    async function openRun(e) {
        e.preventDefault();
        setOpening(true);
        setFormErrors({});

        try {
            const { data } = await api.post('/hrms/offboarding/cases', {
                employee_id: Number(form.employee_id),
                last_working_day: form.last_working_day,
                reason: form.reason,
                notice_period_days: form.notice_period_days ? Number(form.notice_period_days) : undefined,
            });

            toast.success('Exit run opened.');
            setForm({ employee_id: '', last_working_day: '', reason: 'resigned', notice_period_days: '' });
            setModal(false);
            navigate(`/hrms/offboarding/cases/${data.case.id}`);
        } catch (err) {
            setFormErrors(fieldErrors(err));
        } finally {
            setOpening(false);
        }
    }

    const hasRealCases = cases && cases.length > 0;
    const upcomingList = hasRealCases
        ? cases.slice(0, 5).map((c, idx) => ({
              id: c.id,
              initials: (c.employee?.name || 'EM').slice(0, 2).toUpperCase(),
              name: c.employee?.name || 'Employee',
              dateDept: `${c.last_working_day || 'Upcoming'} · ${c.employee?.department?.name || 'Operations'}`,
              status: c.status_label || c.status,
              variant: 'warning',
              color: WORKFLOW_STEPS[idx % WORKFLOW_STEPS.length].color,
              link: `/hrms/offboarding/cases/${c.id}`,
          }))
        : MOCK_EXITS;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">
                        Offboarding & exit management
                    </h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Coordinate notice periods, clearances, asset returns and access revocation.
                    </p>
                </div>

                {canManage && (
                    <button
                        type="button"
                        onClick={() => setModal(true)}
                        className="inline-flex items-center justify-center rounded-lg bg-[#4B5EF5] px-4 py-2.5 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                    >
                        + Start offboarding
                    </button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="Upcoming exits"
                    value={6}
                    pillText="Next 30 days"
                    pillVariant="healthy"
                    accentColor="#DA972E"
                />
                <MetricCard
                    label="Clearance pending"
                    value={4}
                    pillText="Review"
                    pillVariant="healthy"
                    accentColor="#E05260"
                />
                <MetricCard
                    label="Exit interviews"
                    value={3}
                    pillText="Scheduled"
                    pillVariant="healthy"
                    accentColor="#4B5EF5"
                />
                <MetricCard
                    label="Completed this quarter"
                    value={12}
                    pillText="+2"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
            </div>

            {/* Middle Section: Workflow tracker (Left) & Upcoming exits (Right) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                {/* Left: Exit workflow tracker */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-8">
                    <div className="mb-4">
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">Exit workflow tracker</h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                            Every exit task has an owner, due date and audit history.
                        </p>
                    </div>

                    <div className="divide-y divide-[#F0F2F7]">
                        {WORKFLOW_STEPS.map((step) => (
                            <div key={step.num} className="flex items-center justify-between py-3.5 first:pt-1 last:pb-1">
                                <div className="flex items-center gap-3.5">
                                    <div
                                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[13px] font-bold text-white shadow-xs"
                                        style={{ backgroundColor: step.color }}
                                    >
                                        {step.num}
                                    </div>
                                    <span className="text-[14px] font-medium text-[#171C2C]">{step.title}</span>
                                </div>
                                <span className="text-[13px] text-[#5A6478]">{step.count}</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Right: Upcoming exits */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-4">
                    <div className="mb-4">
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">Upcoming exits</h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">Employee lifecycle</p>
                    </div>

                    <div className="divide-y divide-[#F0F2F7]">
                        {upcomingList.map((item) => (
                            <div key={item.id} className="py-3.5 first:pt-1 last:pb-1">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-3">
                                        <div
                                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-white shadow-xs"
                                            style={{ backgroundColor: item.color }}
                                        >
                                            {item.initials}
                                        </div>
                                        <div>
                                            {item.link ? (
                                                <Link to={item.link} className="text-[13px] font-medium text-[#171C2C] hover:text-[#4B5EF5]">
                                                    {item.name}
                                                </Link>
                                            ) : (
                                                <span className="text-[13px] font-medium text-[#171C2C]">{item.name}</span>
                                            )}
                                            <p className="text-[11px] text-[#8C96A8]">{item.dateDept}</p>
                                        </div>
                                    </div>
                                    <StatusPill variant={item.variant}>{item.status}</StatusPill>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Real Runs List (if backend records present) */}
            {cases && cases.length > 0 && (
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                    <h2 className="mb-4 text-[16px] font-semibold text-[#171C2C]">All exit runs</h2>
                    <div className="divide-y divide-[#F0F2F7]">
                        {cases.map((row) => (
                            <div key={row.id} className="flex items-center justify-between py-3">
                                <div>
                                    <Link to={`/hrms/offboarding/cases/${row.id}`} className="font-medium text-[#4B5EF5] hover:underline text-[13px]">
                                        {row.employee?.name || '—'}
                                    </Link>
                                    <span className="ml-3 text-[12px] text-[#8C96A8]">
                                        Last day: {row.last_working_day || '—'} · Reason: {row.reason}
                                    </span>
                                </div>
                                <StatusPill variant={row.status === 'completed' ? 'healthy' : 'warning'}>
                                    {row.status_label || row.status}
                                </StatusPill>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Start Offboarding Modal */}
            <Modal open={modal} onClose={() => setModal(false)} title="Start employee offboarding" size="md">
                <form onSubmit={openRun} className="space-y-4">
                    <Select
                        label="Employee"
                        value={form.employee_id}
                        onChange={(e) => setForm({ ...form, employee_id: e.target.value })}
                        error={formErrors.employee_id}
                    >
                        <option value="">Select employee</option>
                        {(employees ?? []).map((e) => (
                            <option key={e.id} value={e.id}>
                                {e.name}
                            </option>
                        ))}
                    </Select>
                    <Input
                        label="Last working day"
                        type="date"
                        value={form.last_working_day}
                        onChange={(e) => setForm({ ...form, last_working_day: e.target.value })}
                        error={formErrors.last_working_day}
                    />
                    <Select
                        label="Reason"
                        value={form.reason}
                        onChange={(e) => setForm({ ...form, reason: e.target.value })}
                        error={formErrors.reason}
                    >
                        {REASONS.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </Select>
                    <Input
                        label="Notice period (days)"
                        type="number"
                        placeholder="30"
                        value={form.notice_period_days}
                        onChange={(e) => setForm({ ...form, notice_period_days: e.target.value })}
                        error={formErrors.notice_period_days}
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setModal(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={opening} disabled={!form.employee_id || !form.last_working_day}>
                            Initiate exit run
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
