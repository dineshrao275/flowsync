import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Modal from '../../components/ui/Modal';
import Spinner from '../../components/ui/Spinner';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { monthLabel, PAYSLIP_STATUS_LABELS } from '../../utils/payroll';

/**
 * The caller's own payslips across runs, newest first.
 *
 * Self-scoped on purpose — this page rides the module alone like My files,
 * because it only ever shows the caller's own rows. Each row expands to the
 * full breakdown with the signed download link.
 */
export default function MyPayslips() {
    usePageTitle('My payslips');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'My payslips' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);

        return api
            .get('/hrms/payroll/my-payslips')
            .then(({ data: response }) => setData(response))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError('Unable to load your payslips.');
            });
    }, [navigate]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const rows = data?.payslips ?? null;

    function lines(title, list, key = 'monthly') {
        if (!list || list.length === 0) return null;

        return (
            <div className="mt-4">
                <h4 className="mb-1 text-sm font-semibold text-gray-900">{title}</h4>
                <ul className="divide-y divide-gray-100 text-sm">
                    {list.map((row, i) => (
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
                <h2 className="text-xl font-semibold text-gray-900">My payslips</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {rows ? `${rows.length} payslip${rows.length === 1 ? '' : 's'}` : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            {!rows ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : rows.length === 0 ? (
                <EmptyState title="No payslips yet" hint="Your payslips appear here once payroll publishes a run." />
            ) : (
                <Card dense>
                    <ul className="divide-y divide-gray-100">
                        {rows.map((payslip) => (
                            <li key={payslip.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <button type="button" className="font-medium text-indigo-600 hover:underline" onClick={() => setSelected(payslip)}>
                                        {payslip.run ? monthLabel(payslip.run.period_year, payslip.run.period_month) : `Payslip #${payslip.id}`}
                                    </button>
                                    <span className="ml-2 text-xs text-gray-400">{PAYSLIP_STATUS_LABELS[payslip.status] ?? payslip.status}</span>
                                    <p className="text-sm text-gray-500">net {payslip.net_pay} · paid {payslip.paid_days} of {payslip.working_days}</p>
                                </div>
                                <div className="flex gap-2">
                                    <Button size="sm" variant="secondary" onClick={() => setSelected(payslip)}>View</Button>
                                    {payslip.download_url && (
                                        <a href={payslip.download_url} target="_blank" rel="noopener noreferrer">
                                            <Button size="sm" variant="secondary">Download</Button>
                                        </a>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Modal open={!!selected} onClose={() => setSelected(null)} title={selected?.run ? monthLabel(selected.run.period_year, selected.run.period_month) : 'Payslip'} size="lg">
                {selected && (
                    <div className="text-sm">
                        <p className="text-gray-500">
                            Paid {selected.paid_days} of {selected.working_days} · LOP {selected.lop_days} · overtime {selected.ot_minutes} min
                        </p>
                        {lines('Earnings', selected.earnings)}
                        {lines('Deductions', selected.deductions)}
                        {lines('Adjustments', selected.adjustments, 'amount')}
                        <div className="mt-4 rounded-lg border border-gray-200 p-3">
                            <div className="flex justify-between py-0.5"><span className="text-gray-600">Gross</span><span className="font-medium">{selected.gross_pay}</span></div>
                            <div className="flex justify-between py-0.5"><span className="text-gray-600">Deductions</span><span className="font-medium">{selected.total_deductions}</span></div>
                            <div className="flex justify-between py-0.5"><span className="font-semibold text-gray-900">Net</span><span className="font-semibold text-gray-900">{selected.net_pay}</span></div>
                        </div>
                        {selected.download_url && (
                            <div className="mt-4">
                                <a href={selected.download_url} target="_blank" rel="noopener noreferrer">
                                    <Button variant="secondary" size="sm">Download payslip</Button>
                                </a>
                            </div>
                        )}
                    </div>
                )}
            </Modal>
        </div>
    );
}
