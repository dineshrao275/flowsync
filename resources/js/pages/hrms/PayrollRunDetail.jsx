import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { monthLabel, PAYSLIP_STATUS_LABELS } from '../../utils/payroll';

/**
 * One run's review grid: every payslip's LOP, leave and overtime columns,
 * a drill-down per row, and the adjustment drawer.
 *
 * Adjustments are review-only — past review the buttons hide (the backend
 * 422s regardless). The drill-down carries the signed download link, so a
 * reviewer opens the printable payslip without a second lookup.
 */
export default function PayrollRunDetail() {
    usePageTitle('Pay run');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const { runId } = useParams();

    const [run, setRun] = useState(null);
    const [payslips, setPayslips] = useState(null);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState(null);

    const [adjustModal, setAdjustModal] = useState(false);
    const [adjustForm, setAdjustForm] = useState({ kind: 'earning', label: '', amount: '' });
    const [adjustErrors, setAdjustErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Payroll', to: '/hrms/payroll' }, { label: 'Run' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);

        return api
            .get(`/hrms/payroll/runs/${runId}/payslips`)
            .then(({ data }) => {
                setRun(data.run);
                setPayslips(data.payslips ?? []);
            })
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError('Unable to load this pay run.');
            });
    }, [runId, navigate]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [runId]);

    const editable = run?.status === 'review';

    async function saveAdjustment(e) {
        e.preventDefault();
        setAdjustErrors({});

        try {
            const { data } = await api.post(`/hrms/payroll/payslips/${selected.id}/adjustments`, adjustForm);
            toast.success('Adjustment added.');
            setAdjustModal(false);
            setAdjustForm({ kind: 'earning', label: '', amount: '' });
            setSelected(data.payslip);
            load();
        } catch (err) {
            setAdjustErrors(fieldErrors(err));
        }
    }

    async function removeAdjustment(adjustment) {
        if (!window.confirm(`Remove “${adjustment.label}”? Totals recompute without it.`)) return;

        try {
            const { data } = await api.delete(`/hrms/payroll/payslips/${selected.id}/adjustments/${adjustment.id}`);
            toast.success('Adjustment removed.');
            setSelected(data.payslip);
            load();
        } catch {
            setError('That adjustment cannot be removed.');
        }
    }

    function lines(title, rows, key = 'monthly') {
        if (!rows || rows.length === 0) return null;

        return (
            <div className="mt-4">
                <h4 className="mb-1 text-sm font-semibold text-gray-900">{title}</h4>
                <ul className="divide-y divide-gray-100 text-sm">
                    {rows.map((row, i) => (
                        <li key={row.id ?? `${row.code}-${i}`} className="flex justify-between gap-3 py-1">
                            <span className="text-gray-600">{row.label ?? row.name}</span>
                            <span className="font-medium text-gray-900">{row.kind === 'deduction' ? '−' : ''}{row[key] ?? row.amount}</span>
                        </li>
                    ))}
                </ul>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">
                    {run ? monthLabel(run.period_year, run.period_month) : 'Pay run'}
                </h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {run ? `${run.status} · ${run.employee_count} people · net ${run.totals?.net_pay ?? '—'}` : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            {!payslips ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : payslips.length === 0 ? (
                <EmptyState title="No payslips yet" hint="Calculate the run from the Payroll page first." />
            ) : (
                <Card dense>
                    <Table>
                        <thead>
                            <tr><Th>Employee</Th><Th>Paid / Working</Th><Th>LOP</Th><Th>Leave (paid/unpaid)</Th><Th>OT min</Th><Th>Gross</Th><Th>Net</Th><Th>Status</Th></tr>
                        </thead>
                        <tbody>
                            {payslips.map((payslip) => (
                                <tr key={payslip.id}>
                                    <Td>
                                        <button type="button" className="font-medium text-indigo-600 hover:underline" onClick={() => setSelected(payslip)}>
                                            {payslip.employee?.name ?? `#${payslip.id}`}
                                        </button>
                                        <span className="ml-2 text-xs text-gray-400">{payslip.employee?.employee_code}</span>
                                    </Td>
                                    <Td>{payslip.paid_days} / {payslip.working_days}</Td>
                                    <Td>{payslip.lop_days}</Td>
                                    <Td>{payslip.leave_days?.paid_days ?? 0} / {payslip.leave_days?.unpaid_days ?? 0}</Td>
                                    <Td>{payslip.ot_minutes}</Td>
                                    <Td>{payslip.gross_pay}</Td>
                                    <Td>{payslip.net_pay}</Td>
                                    <Td>{PAYSLIP_STATUS_LABELS[payslip.status] ?? payslip.status}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            )}

            <Modal open={!!selected} onClose={() => setSelected(null)} title={selected?.employee?.name ?? 'Payslip'} size="lg">
                {selected && (
                    <div className="text-sm">
                        <p className="text-gray-500">
                            {selected.employee?.employee_code} · paid {selected.paid_days} of {selected.working_days} · LOP {selected.lop_days} · overtime {selected.ot_minutes} min
                        </p>

                        {lines('Earnings', selected.earnings)}
                        {lines('Deductions', selected.deductions)}
                        {lines('Employer contributions', selected.employer_contributions)}
                        {lines('Adjustments', selected.adjustments, 'amount')}

                        <div className="mt-4 rounded-lg border border-gray-200 p-3">
                            <div className="flex justify-between py-0.5"><span className="text-gray-600">Gross</span><span className="font-medium">{selected.gross_pay}</span></div>
                            <div className="flex justify-between py-0.5"><span className="text-gray-600">Deductions</span><span className="font-medium">{selected.total_deductions}</span></div>
                            <div className="flex justify-between py-0.5"><span className="font-semibold text-gray-900">Net</span><span className="font-semibold text-gray-900">{selected.net_pay}</span></div>
                        </div>

                        <div className="mt-4 flex flex-wrap gap-2">
                            {selected.download_url && (
                                <a href={selected.download_url} target="_blank" rel="noopener noreferrer">
                                    <Button variant="secondary" size="sm">Download payslip</Button>
                                </a>
                            )}
                            {editable && <Button size="sm" onClick={() => setAdjustModal(true)}>Add adjustment</Button>}
                        </div>

                        {editable && selected.adjustments?.length > 0 && (
                            <div className="mt-3">
                                {selected.adjustments.map((adjustment) => (
                                    <div key={adjustment.id} className="flex items-center justify-between gap-2 py-1 text-sm">
                                        <span>{adjustment.label}</span>
                                        <Button size="sm" variant="danger" onClick={() => removeAdjustment(adjustment)}>Remove</Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </Modal>

            <Modal open={adjustModal} onClose={() => setAdjustModal(false)} title="Add adjustment" subtitle="Review runs only — past review the backend refuses.">
                <form onSubmit={saveAdjustment} className="grid gap-3">
                    <Select label="Kind" value={adjustForm.kind} error={adjustErrors.kind} onChange={(e) => setAdjustForm({ ...adjustForm, kind: e.target.value })}>
                        <option value="earning">Earning (adds to net)</option>
                        <option value="deduction">Deduction (takes from net)</option>
                    </Select>
                    <Input label="Label" value={adjustForm.label} error={adjustErrors.label} onChange={(e) => setAdjustForm({ ...adjustForm, label: e.target.value })} required />
                    <Input label="Amount" value={adjustForm.amount} error={adjustErrors.amount} onChange={(e) => setAdjustForm({ ...adjustForm, amount: e.target.value })} required />
                    <div><Button type="submit">Add line</Button></div>
                </form>
            </Modal>
        </div>
    );
}
