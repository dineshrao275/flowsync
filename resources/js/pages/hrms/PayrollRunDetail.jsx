import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
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
import { useConfirm } from '../../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { monthLabel, payslipStatusLabel } from '../../utils/payroll';

/**
 * One run's review grid: every payslip's LOP, leave and overtime columns,
 * a drill-down per row, and the adjustment drawer.
 *
 * Adjustments are review-only — past review the buttons hide (the backend
 * 422s regardless). The drill-down carries the signed download link, so a
 * reviewer opens the printable payslip without a second lookup.
 */
export default function PayrollRunDetail() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.payrollRun.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const confirm = useConfirm();
    const { runId } = useParams();

    const [run, setRun] = useState(null);
    const [payslips, setPayslips] = useState(null);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState(null);

    const [adjustModal, setAdjustModal] = useState(false);
    const [adjustForm, setAdjustForm] = useState({ kind: 'earning', label: '', amount: '' });
    const [adjustErrors, setAdjustErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: t('hrms.payroll.pageTitle'), to: '/hrms/payroll' }, { label: t('hrms.payrollRun.crumbRun') }]);
    }, [setCrumbs, t]);

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

                setError(t('hrms.payrollRun.loadFailed'));
            });
    }, [runId, navigate, t]);

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
            toast.success(t('hrms.payrollRun.adjustAdded'));
            setAdjustModal(false);
            setAdjustForm({ kind: 'earning', label: '', amount: '' });
            setSelected(data.payslip);
            load();
        } catch (err) {
            setAdjustErrors(fieldErrors(err));
        }
    }

    async function removeAdjustment(adjustment) {
        if (!(await confirm({ title: t('hrms.payrollRun.removeConfirmTitle'), message: t('hrms.payrollRun.removeConfirmMessage', { label: adjustment.label }), variant: 'danger' }))) return;

        try {
            const { data } = await api.delete(`/hrms/payroll/payslips/${selected.id}/adjustments/${adjustment.id}`);
            toast.success(t('hrms.payrollRun.adjustRemoved'));
            setSelected(data.payslip);
            load();
        } catch {
            setError(t('hrms.payrollRun.adjustRemoveFailed'));
        }
    }

    async function downloadPayout(format) {
        setError(null);
        try {
            const res = await api.get(`/hrms/payroll/runs/${runId}/bank-file`, { params: { format }, responseType: 'blob' });
            const url = URL.createObjectURL(res.data);
            const a = document.createElement('a');
            a.href = url;
            a.download = `payout-run-${runId}-${format}.csv`;
            a.click();
            URL.revokeObjectURL(url);
            const missing = Number(res.headers['x-missing-bank'] || 0);
            if (missing > 0) setError(t('hrms.payrollRun.payoutMissing', { count: missing }));
        } catch {
            setError(t('hrms.payrollRun.payoutFailed'));
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
                    {run ? monthLabel(run.period_year, run.period_month) : t('hrms.payrollRun.pageTitle')}
                </h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {run ? `${run.status} · ${t('hrms.payrollRun.people', { count: run.employee_count })} · ${t('hrms.payrollRun.net')} ${run.totals?.net_pay ?? '—'}` : '—'}
                </p>
                {run && ['approved', 'processing', 'paid', 'locked'].includes(run.status) && (
                    <div className="mt-2 flex gap-2">
                        <Button size="sm" variant="secondary" onClick={() => downloadPayout('generic')}>{t('hrms.payrollRun.payoutGeneric')}</Button>
                        <Button size="sm" variant="secondary" onClick={() => downloadPayout('neft')}>{t('hrms.payrollRun.payoutNeft')}</Button>
                    </div>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {!payslips ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : payslips.length === 0 ? (
                <EmptyState title={t('hrms.payrollRun.emptyTitle')} hint={t('hrms.payrollRun.emptyHint')} />
            ) : (
                <Card dense>
                    <Table>
                        <thead>
                            <tr><Th>{t('hrms.payrollRun.employee')}</Th><Th>{t('hrms.payrollRun.paidWorking')}</Th><Th>{t('hrms.payrollRun.lop')}</Th><Th>{t('hrms.payrollRun.leave')}</Th><Th>{t('hrms.payrollRun.otMin')}</Th><Th>{t('hrms.payrollRun.gross')}</Th><Th>{t('hrms.payrollRun.netLabel')}</Th><Th>{t('hrms.payrollRun.status')}</Th></tr>
                        </thead>
                        <tbody>
                            {payslips.map((payslip) => (
                                <tr key={payslip.id}>
                                    <Td>
                                        <button type="button" className="font-medium text-[var(--accent-soft-text)] hover:underline" onClick={() => setSelected(payslip)}>
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
                                    <Td>{payslipStatusLabel(t, payslip.status)}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            )}

            <Modal open={!!selected} onClose={() => setSelected(null)} title={selected?.employee?.name ?? t('hrms.payrollRun.payslip')} size="lg">
                {selected && (
                    <div className="px-6 py-5 text-sm">
                        <p className="text-gray-500">
                            {selected.employee?.employee_code} · {t('hrms.payrollRun.payslipMeta', { paid: selected.paid_days, working: selected.working_days, lop: selected.lop_days, ot: selected.ot_minutes })}
                        </p>

                        {lines(t('hrms.payrollRun.earnings'), selected.earnings)}
                        {lines(t('hrms.payrollRun.deductions'), selected.deductions)}
                        {lines(t('hrms.payrollRun.employerContributions'), selected.employer_contributions)}
                        {lines(t('hrms.payrollRun.adjustments'), selected.adjustments, 'amount')}

                        <div className="mt-4 rounded-lg border border-gray-200 p-3">
                            <div className="flex justify-between py-0.5"><span className="text-gray-600">{t('hrms.payrollRun.gross')}</span><span className="font-medium">{selected.gross_pay}</span></div>
                            <div className="flex justify-between py-0.5"><span className="text-gray-600">{t('hrms.payrollRun.deductions')}</span><span className="font-medium">{selected.total_deductions}</span></div>
                            <div className="flex justify-between py-0.5"><span className="font-semibold text-gray-900">{t('hrms.payrollRun.netLabel')}</span><span className="font-semibold text-gray-900">{selected.net_pay}</span></div>
                        </div>

                        <div className="mt-4 flex flex-wrap gap-2">
                            {selected.download_url && (
                                <a href={selected.download_url} target="_blank" rel="noopener noreferrer">
                                    <Button variant="secondary" size="sm">{t('hrms.payrollRun.downloadPayslip')}</Button>
                                </a>
                            )}
                            {editable && <Button size="sm" onClick={() => setAdjustModal(true)}>{t('hrms.payrollRun.addAdjustment')}</Button>}
                        </div>

                        {editable && selected.adjustments?.length > 0 && (
                            <div className="mt-3">
                                {selected.adjustments.map((adjustment) => (
                                    <div key={adjustment.id} className="flex items-center justify-between gap-2 py-1 text-sm">
                                        <span>{adjustment.label}</span>
                                        <Button size="sm" variant="danger" onClick={() => removeAdjustment(adjustment)}>{t('hrms.common.remove')}</Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </Modal>

            <Modal open={adjustModal} onClose={() => setAdjustModal(false)} title={t('hrms.payrollRun.adjustModalTitle')} subtitle={t('hrms.payrollRun.adjustModalSubtitle')}>
                <form onSubmit={saveAdjustment} className="grid gap-3 px-6 py-5">
                    <Select label={t('hrms.payrollRun.kind')} value={adjustForm.kind} error={adjustErrors.kind} onChange={(e) => setAdjustForm({ ...adjustForm, kind: e.target.value })}>
                        <option value="earning">{t('hrms.payrollRun.kindEarning')}</option>
                        <option value="deduction">{t('hrms.payrollRun.kindDeduction')}</option>
                    </Select>
                    <Input label={t('hrms.payrollRun.label')} value={adjustForm.label} error={adjustErrors.label} onChange={(e) => setAdjustForm({ ...adjustForm, label: e.target.value })} required />
                    <Input label={t('hrms.payrollRun.amount')} value={adjustForm.amount} error={adjustErrors.amount} onChange={(e) => setAdjustForm({ ...adjustForm, amount: e.target.value })} required />
                    <div><Button type="submit">{t('hrms.payrollRun.addLine')}</Button></div>
                </form>
            </Modal>
        </div>
    );
}
