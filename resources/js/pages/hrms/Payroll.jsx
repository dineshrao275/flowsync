import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { monthLabel, RUN_STATUS_STEPS } from '../../utils/payroll';

const emptyRun = { period_year: String(new Date().getFullYear()), period_month: String(new Date().getMonth() + 1), pay_period_start: '', pay_period_end: '', pay_date: '', notes: '' };

/**
 * Pay runs: open a month, calculate it, and walk it to locked.
 *
 * A runner tool end to end — the route needs `hrms.payroll.run`, and every
 * row action mirrors the run's own state: calculate on draft/review,
 * approve on review, publish on approved, mark-paid on processing, lock on
 * paid. The review grid itself lives on the run detail page.
 */
export default function Payroll() {
    usePageTitle('Payroll');
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
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Payroll' }]);
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

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

    function actionsFor(run) {
        const busy = acting !== null;
        const button = (action, label, done, variant = 'secondary') => (
            <Button
                key={action}
                size="sm"
                variant={variant}
                disabled={busy}
                loading={acting === `${run.id}:${action}`}
                onClick={() => transition(run, action, done)}
            >
                {label}
            </Button>
        );

        switch (run.status) {
            case 'draft':
            case 'review':
                return [button('calculate', 'Calculate', 'Run calculated.', 'primary'), run.status === 'review' && button('approve', 'Approve', 'Run approved.')];
            case 'approved':
                return [button('publish', 'Publish', 'Payslips published.', 'primary')];
            case 'processing':
                return [button('mark-paid', 'Mark paid', 'Run marked paid.', 'primary')];
            case 'paid':
                return [button('lock', 'Lock', 'Run locked.', 'warning')];
            default:
                return [];
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Payroll</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {runs ? `${runs.length} run${runs.length === 1 ? '' : 's'}` : '—'}
                    </p>
                </div>
                <Button onClick={() => setModal(true)}>Open a run</Button>
            </div>

            {error && <Alert>{error}</Alert>}

            {!runs ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : runs.length === 0 ? (
                <EmptyState title="No pay runs yet" hint="Open a run for the current month to price it." />
            ) : (
                <Card dense>
                    <Table>
                        <thead>
                            <tr><Th>Period</Th><Th>Pay date</Th><Th>Status</Th><Th>People</Th><Th>Net pay</Th><Th><span className="sr-only">Actions</span></Th></tr>
                        </thead>
                        <tbody>
                            {runs.map((run) => (
                                <tr key={run.id}>
                                    <Td>
                                        <Link to={`/hrms/payroll/runs/${run.id}`} className="font-medium text-indigo-600 hover:underline">
                                            {monthLabel(run.period_year, run.period_month)}
                                        </Link>
                                        <span className="ml-2 text-xs text-gray-400">{RUN_STATUS_STEPS[run.status] ?? run.status}</span>
                                    </Td>
                                    <Td>{run.pay_date}</Td>
                                    <Td>{run.status}</Td>
                                    <Td>{run.employee_count}</Td>
                                    <Td>{run.totals?.net_pay ?? '—'}</Td>
                                    <Td><div className="flex flex-wrap gap-2">{actionsFor(run)}</div></Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            )}

            <Modal open={modal} onClose={() => setModal(false)} title="Open a pay run" subtitle="One run prices one calendar month.">
                <form onSubmit={openRun} className="grid gap-3 sm:grid-cols-2">
                    <Input label="Year" value={form.period_year} error={formErrors.period_year} onChange={(e) => setForm({ ...form, period_year: e.target.value })} required />
                    <Input label="Month (1–12)" value={form.period_month} error={formErrors.period_month} onChange={(e) => setForm({ ...form, period_month: e.target.value })} required />
                    <Input label="Period start" type="date" value={form.pay_period_start} error={formErrors.pay_period_start} onChange={(e) => setForm({ ...form, pay_period_start: e.target.value })} required />
                    <Input label="Period end" type="date" value={form.pay_period_end} error={formErrors.pay_period_end} onChange={(e) => setForm({ ...form, pay_period_end: e.target.value })} required />
                    <Input label="Pay date" type="date" value={form.pay_date} error={formErrors.pay_date} onChange={(e) => setForm({ ...form, pay_date: e.target.value })} required />
                    <Input label="Notes" value={form.notes} error={formErrors.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                    <div className="sm:col-span-2"><Button type="submit">Open run</Button></div>
                </form>
            </Modal>
        </div>
    );
}
