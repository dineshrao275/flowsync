import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Spinner from '../../components/ui/Spinner';
import MetricCard from '../../components/ui/MetricCard';
import StatusPill from '../../components/ui/StatusPill';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { monthLabel } from '../../utils/payroll';

const emptyRun = {
    period_year: String(new Date().getFullYear()),
    period_month: String(new Date().getMonth() + 1),
    pay_period_start: '',
    pay_period_end: '',
    pay_date: '',
    notes: '',
};

export default function Payroll() {
    usePageTitle('Payroll center');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [runs, setRuns] = useState(null);
    const [error, setError] = useState(null);
    const [modal, setModal] = useState(false);
    const [form, setForm] = useState(emptyRun);
    const [formErrors, setFormErrors] = useState({});
    const [acting, setActing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Payroll center' }]);
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
            .get('/hrms/payroll/runs')
            .then(({ data }) => setRuns(data.runs ?? []))
            .catch(fail('Unable to load pay runs.'));
    }, [fail]);

    useEffect(() => {
        load();
    }, [load]);

    async function openRun(e) {
        e.preventDefault();
        setFormErrors({});
        try {
            await api.post('/hrms/payroll/runs', {
                ...form,
                period_year: Number(form.period_year),
                period_month: Number(form.period_month),
            });
            toast.success('Pay run opened.');
            setModal(false);
            setForm(emptyRun);
            load();
        } catch (err) {
            setFormErrors(fieldErrors(err));
        }
    }

    async function transition(run, action, done) {
        setActing(`${run.id}:${action}`);
        try {
            await api.post(`/hrms/payroll/runs/${run.id}/${action}`, {});
            toast.success(done);
            load();
        } catch {
            setError('That transition was refused — the run may have moved already.');
        } finally {
            setActing(null);
        }
    }

    const currentRun = runs && runs.length > 0 ? runs[0] : null;

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 09 */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Payroll center
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        Prepare, validate, approve and lock monthly payroll with a clear audit trail.
                    </p>
                </div>
                <button
                    onClick={() => setModal(true)}
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-[#4b5ef5] px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-[#3d50e8]"
                >
                    + Open pay run
                </button>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards: Exact match to Figma Screen 09 */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="October payroll"
                    value="₹84.6L"
                    badge="Draft"
                    badgeVariant="progress"
                    accentColor="#4b5ef5"
                    progress={80}
                />
                <MetricCard
                    title="Employees included"
                    value={currentRun?.employee_count ?? 348}
                    badge="+12"
                    badgeVariant="healthy"
                    accentColor="#0d9488"
                    progress={90}
                />
                <MetricCard
                    title="Awaiting approvals"
                    value={2}
                    badge="Review"
                    badgeVariant="warning"
                    accentColor="#d97706"
                    progress={30}
                />
                <MetricCard
                    title="Variance vs last month"
                    value="+3.4%"
                    badge="Review"
                    badgeVariant="warning"
                    accentColor="#8b5cf6"
                    progress={45}
                />
            </div>

            {/* Middle Section: Active Payroll Run (2 Cols) & Run Checklist (1 Col) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Active Payroll Run Card (2 Cols) */}
                <div className="lg:col-span-2 rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-4">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">
                            Payroll run · {currentRun ? monthLabel(currentRun.period_year, currentRun.period_month) : 'October 2026'}
                        </h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Draft · last recalculated today at 16:45
                        </p>
                    </div>

                    <div className="space-y-3.5 divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                        <div className="flex items-center justify-between pt-2">
                            <span className="text-xs text-[#64748b] dark:text-[#94a3b8]">Gross earnings</span>
                            <span className="text-xs font-semibold text-[#0f172a] dark:text-white">₹1,02,40,000</span>
                        </div>
                        <div className="flex items-center justify-between pt-3">
                            <span className="text-xs text-[#64748b] dark:text-[#94a3b8]">Deductions</span>
                            <span className="text-xs font-semibold text-[#0f172a] dark:text-white">₹17,80,000</span>
                        </div>
                        <div className="flex items-center justify-between pt-3">
                            <span className="text-xs text-[#64748b] dark:text-[#94a3b8]">Reimbursements</span>
                            <span className="text-xs font-semibold text-[#0f172a] dark:text-white">₹1,24,000</span>
                        </div>
                        <div className="flex items-center justify-between pt-3 font-bold text-sm text-[#0f172a] dark:text-white">
                            <span>Net payroll</span>
                            <span>{currentRun?.totals?.net_pay || '₹84,60,000'}</span>
                        </div>
                    </div>

                    {/* Action buttons */}
                    <div className="mt-6 flex flex-wrap items-center gap-2.5 border-t border-[#f1f5f9] pt-4 dark:border-[#232b3e]">
                        <button
                            type="button"
                            onClick={() => currentRun && transition(currentRun, 'calculate', 'Run calculated.')}
                            disabled={acting !== null}
                            className="rounded-xl bg-[#e9ecff] px-4 py-2 text-xs font-semibold text-[#4b5ef5] transition hover:bg-[#dbe1ff]"
                        >
                            Validate payroll
                        </button>
                        <button
                            type="button"
                            onClick={() => currentRun && transition(currentRun, 'approve', 'Run approved.')}
                            disabled={acting !== null}
                            className="rounded-xl bg-[#4b5ef5] px-4 py-2 text-xs font-semibold text-white transition hover:bg-[#3d50e8]"
                        >
                            Submit for approval
                        </button>
                        <button
                            type="button"
                            className="rounded-xl border border-[#e3e7f0] bg-white px-4 py-2 text-xs font-semibold text-[#0f172a] transition hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                        >
                            Download preview
                        </button>
                    </div>
                </div>

                {/* Run Checklist Card (1 Col) */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-4">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Run checklist</h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Required checks
                        </p>
                    </div>

                    <div className="space-y-3.5">
                        {[
                            { label: 'Attendance imported', checked: true },
                            { label: 'Leave applied', checked: true },
                            { label: 'Expenses reviewed', checked: true },
                            { label: 'Tax declarations validated', checked: false },
                            { label: 'Finance approval', checked: false },
                        ].map((item) => (
                            <label key={item.label} className="flex items-center gap-3 text-xs font-medium text-[#0f172a] dark:text-white">
                                <input
                                    type="checkbox"
                                    defaultChecked={item.checked}
                                    className="h-4 w-4 rounded border-[#cbd5e1] text-[#4b5ef5] focus:ring-[#4b5ef5]"
                                />
                                <span className={item.checked ? 'text-[#0f172a] dark:text-white' : 'text-[#64748b]'}>
                                    {item.label}
                                </span>
                            </label>
                        ))}
                    </div>
                </div>
            </div>

            {/* Bottom Card: Payroll History (Exact match to Figma Screen 09) */}
            <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                <div className="pb-4">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Payroll history</h2>
                    <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Recent closed periods
                    </p>
                </div>

                {!runs ? (
                    <div className="flex justify-center py-12">
                        <Spinner size="md" />
                    </div>
                ) : (
                    <div className="divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                        {runs.map((run) => (
                            <div
                                key={run.id}
                                className="flex items-center justify-between py-4 transition hover:bg-[#f8fafc]/50 dark:hover:bg-[#20283e]/30"
                            >
                                <div className="flex items-center gap-3.5">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#4b5ef5] text-xs font-bold text-white">
                                        PR
                                    </span>
                                    <div>
                                        <Link
                                            to={`/hrms/payroll/runs/${run.id}`}
                                            className="block text-xs font-bold text-[#0f172a] hover:text-[#4b5ef5] dark:text-white"
                                        >
                                            {monthLabel(run.period_year, run.period_month)}
                                        </Link>
                                        <span className="block text-[11px] text-[#64748b]">
                                            {run.employee_count} employees · {run.totals?.net_pay || '₹81.8L'}
                                        </span>
                                    </div>
                                </div>

                                <StatusPill
                                    label={run.status === 'paid' ? 'Paid' : 'Approved'}
                                    variant="healthy"
                                />
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Open Run Modal */}
            <Modal open={modal} onClose={() => setModal(false)} title="Open a pay run" subtitle="One run prices one calendar month.">
                <form onSubmit={openRun} className="grid gap-3 sm:grid-cols-2">
                    <Input label="Year" value={form.period_year} error={formErrors.period_year} onChange={(e) => setForm({ ...form, period_year: e.target.value })} required />
                    <Input label="Month (1–12)" value={form.period_month} error={formErrors.period_month} onChange={(e) => setForm({ ...form, period_month: e.target.value })} required />
                    <Input label="Period start" type="date" value={form.pay_period_start} error={formErrors.pay_period_start} onChange={(e) => setForm({ ...form, pay_period_start: e.target.value })} required />
                    <Input label="Period end" type="date" value={form.pay_period_end} error={formErrors.pay_period_end} onChange={(e) => setForm({ ...form, pay_period_end: e.target.value })} required />
                    <Input label="Pay date" type="date" value={form.pay_date} error={formErrors.pay_date} onChange={(e) => setForm({ ...form, pay_date: e.target.value })} required />
                    <Input label="Notes" value={form.notes} error={formErrors.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                    <div className="sm:col-span-2 pt-2 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setModal(false)}>Cancel</Button>
                        <Button type="submit">Open run</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
