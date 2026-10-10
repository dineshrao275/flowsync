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

const STAGES = ['goal_setting', 'check_in', 'self_review', 'manager_review', 'calibration', 'completed'];
const STAGE_LABELS = {
    goal_setting: 'Goal setting',
    check_in: 'Check-in',
    self_review: 'Self review',
    manager_review: 'Manager review',
    calibration: 'Calibration',
    completed: 'Completed',
};
const NEXT_STEP = {
    goal_setting: { action: 'open-check-in', label: 'Open check-ins', done: 'Check-ins opened.' },
    check_in: { action: 'open-self-review', label: 'Open self review', done: 'Self review opened.' },
    self_review: { action: 'open-manager-review', label: 'Open manager review', done: 'Manager review opened.' },
    manager_review: { action: 'open-calibration', label: 'Open calibration', done: 'Calibration opened.' },
    calibration: { action: 'complete', label: 'Complete cycle', done: 'Cycle completed.' },
};

const emptyCycle = { name: '', description: '', period_start: '', period_end: '', anonymity: 'none' };

const MOCK_REVIEWS = [
    { id: 1, initials: 'ER', name: 'Elena Rostova', goals: '4 goals', department: 'Engineering', status: 'In progress', variant: 'progress', color: '#4B5EF5' },
    { id: 2, initials: 'MC', name: 'Michael Chen', goals: '3 goals', department: 'Engineering', status: 'Submitted', variant: 'healthy', color: '#1F9B69' },
    { id: 3, initials: 'CD', name: 'Chloe Duong', goals: '5 goals', department: 'Engineering', status: 'Not started', variant: 'warning', color: '#7B61FF' },
    { id: 4, initials: 'SK', name: 'Samira Khan', goals: '4 goals', department: 'Design', status: 'Submitted', variant: 'healthy', color: '#4B5EF5' },
];

export default function Performance() {
    usePageTitle('Performance & goals');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canManage = can('hrms.performance.manage') || can('permission:hrms.performance.manage');

    const [cycles, setCycles] = useState(null);
    const [error, setError] = useState(null);
    const [modal, setModal] = useState(false);
    const [form, setForm] = useState(emptyCycle);
    const [formErrors, setFormErrors] = useState({});
    const [acting, setActing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Performance' }]);
    }, [setCrumbs]);

    const fail = useCallback(
        (message) => (err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }
            setError(message);
        },
        [navigate],
    );

    const load = useCallback(() => {
        setError(null);
        return api
            .get('/hrms/performance/cycles')
            .then(({ data }) => setCycles(data.cycles ?? []))
            .catch(fail('Unable to load cycles.'));
    }, [fail]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function create(e) {
        e.preventDefault();
        setFormErrors({});

        try {
            await api.post('/hrms/performance/cycles', form);
            toast.success('Cycle created in goal setting.');
            setModal(false);
            setForm(emptyCycle);
            load();
        } catch (err) {
            setFormErrors(fieldErrors(err));
        }
    }

    async function advance(cycle, next) {
        setActing(cycle.id);
        try {
            await api.post(`/hrms/performance/cycles/${cycle.id}/${next.action}`);
            toast.success(next.done);
            load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? 'Stage change refused.');
        } finally {
            setActing(null);
        }
    }

    const activeCycle = cycles && cycles.length > 0 ? cycles[0] : null;
    const cycleName = activeCycle ? activeCycle.name : 'Q4 2026';

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">Performance & goals</h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Align goals with business outcomes and link individual goals to project delivery.
                    </p>
                </div>

                {canManage && (
                    <button
                        type="button"
                        onClick={() => setModal(true)}
                        className="inline-flex items-center justify-center rounded-lg bg-[#4B5EF5] px-4 py-2.5 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                    >
                        + Create cycle
                    </button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="Active review cycle"
                    value={cycleName}
                    pillText="Live"
                    pillVariant="healthy"
                    accentColor="#4B5EF5"
                />
                <MetricCard
                    label="Goals on track"
                    value="86%"
                    pillText="+4%"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
                <MetricCard
                    label="Reviews to complete"
                    value="24"
                    pillText="Due soon"
                    pillVariant="healthy"
                    accentColor="#DA972E"
                />
                <MetricCard
                    label="Check-in completion"
                    value="78%"
                    pillText="+9%"
                    pillVariant="healthy"
                    accentColor="#7B61FF"
                />
            </div>

            {/* Employee review progress Card */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-4">
                    <h2 className="text-[16px] font-semibold text-[#171C2C]">Employee review progress</h2>
                    <p className="mt-0.5 text-[12px] text-[#8C96A8]">{cycleName} review cycle · Oct 2026</p>
                </div>

                <div className="divide-y divide-[#F0F2F7]">
                    {MOCK_REVIEWS.map((review) => (
                        <div key={review.id} className="flex items-center justify-between py-3.5 first:pt-1 last:pb-1">
                            <div className="flex items-center gap-3.5">
                                <div
                                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-white shadow-xs"
                                    style={{ backgroundColor: review.color }}
                                >
                                    {review.initials}
                                </div>
                                <span className="text-[13px] font-medium text-[#171C2C]">{review.name}</span>
                            </div>

                            <div className="text-[13px] text-[#8C96A8]">
                                {review.goals} · {review.department}
                            </div>

                            <div>
                                <StatusPill variant={review.variant}>{review.status}</StatusPill>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Cycles Management List (Backend Integrated) */}
            {cycles && cycles.length > 0 && (
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                    <h2 className="mb-4 text-[16px] font-semibold text-[#171C2C]">Review cycles directory</h2>
                    <div className="divide-y divide-[#F0F2F7]">
                        {cycles.map((c) => {
                            const next = NEXT_STEP[c.stage];
                            return (
                                <div key={c.id} className="flex flex-wrap items-center justify-between gap-4 py-4 first:pt-1 last:pb-1">
                                    <div>
                                        <Link to={`/hrms/performance/cycles/${c.id}`} className="text-[14px] font-semibold text-[#171C2C] hover:text-[#4B5EF5] transition-colors">
                                            {c.name}
                                        </Link>
                                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                                            {c.period_start} → {c.period_end || 'ongoing'} · {STAGE_LABELS[c.stage] ?? c.stage}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <div className="flex items-center gap-1.5">
                                            {STAGES.map((s, idx) => (
                                                <span
                                                    key={s}
                                                    className={`h-2 w-5 rounded-full ${
                                                        idx <= STAGES.indexOf(c.stage) ? 'bg-[#4B5EF5]' : 'bg-[#E5E8F0]'
                                                    }`}
                                                    title={STAGE_LABELS[s]}
                                                />
                                            ))}
                                        </div>
                                        {canManage && next && (
                                            <Button
                                                variant="secondary"
                                                onClick={() => advance(c, next)}
                                                loading={acting === c.id}
                                                className="text-[12px]"
                                            >
                                                {next.label}
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* Create Cycle Modal */}
            <Modal open={modal} onClose={() => setModal(false)} title="New review cycle" size="lg">
                <form onSubmit={create} className="space-y-4">
                    <Input
                        label="Cycle name"
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        error={formErrors.name}
                        placeholder="e.g. Q4 2026 Annual Review"
                    />
                    <Input
                        label="Description"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        error={formErrors.description}
                    />
                    <div className="grid grid-cols-2 gap-4">
                        <Input
                            label="Period start"
                            type="date"
                            value={form.period_start}
                            onChange={(e) => setForm({ ...form, period_start: e.target.value })}
                            error={formErrors.period_start}
                        />
                        <Input
                            label="Period end"
                            type="date"
                            value={form.period_end}
                            onChange={(e) => setForm({ ...form, period_end: e.target.value })}
                            error={formErrors.period_end}
                        />
                    </div>
                    <Select
                        label="Feedback anonymity"
                        value={form.anonymity}
                        onChange={(e) => setForm({ ...form, anonymity: e.target.value })}
                    >
                        <option value="none">Named only</option>
                        <option value="optional">Reviewer choice</option>
                        <option value="enforced">Always anonymous</option>
                    </Select>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setModal(false)}>
                            Cancel
                        </Button>
                        <Button type="submit">Create cycle</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
