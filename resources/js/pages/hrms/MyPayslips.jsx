import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Modal from '../../components/ui/Modal';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { monthLabel, payslipStatusLabel } from '../../utils/payroll';

/**
 * The caller's own payslips across runs, newest first.
 *
 * Self-scoped on purpose — this page rides the module alone like My files,
 * because it only ever shows the caller's own rows. Each row expands to the
 * full breakdown with the signed download link.
 */
export default function MyPayslips() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.myPayslips.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: t('hrms.myPayslips.pageTitle') }]);
    }, [setCrumbs, t]);

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

                setError(t('hrms.myPayslips.loadFailed'));
            });
    }, [navigate, t]);

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
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.myPayslips.pageTitle')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {rows ? (rows.length === 1 ? t('hrms.myPayslips.payslipCountOne') : t('hrms.myPayslips.payslipCount', { count: rows.length })) : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            {!rows ? (
                <ContentSkeleton size="md" />
            ) : rows.length === 0 ? (
                <EmptyState title={t('hrms.myPayslips.emptyTitle')} hint={t('hrms.myPayslips.emptyHint')} />
            ) : (
                <Card dense>
                    <ul className="divide-y divide-gray-100">
                        {rows.map((payslip) => (
                            <li key={payslip.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <button type="button" className="font-medium text-[var(--accent-soft-text)] hover:underline" onClick={() => setSelected(payslip)}>
                                        {payslip.run ? monthLabel(payslip.run.period_year, payslip.run.period_month) : t('hrms.myPayslips.payslipFallback', { id: payslip.id })}
                                    </button>
                                    <span className="ml-2 text-xs text-gray-400">{payslipStatusLabel(t, payslip.status)}</span>
                                    <p className="text-sm text-gray-500">{t('hrms.myPayslips.rowMeta', { net: payslip.net_pay, paid: payslip.paid_days, working: payslip.working_days })}</p>
                                </div>
                                <div className="flex gap-2">
                                    <Button size="sm" variant="secondary" onClick={() => setSelected(payslip)}>{t('hrms.common.view')}</Button>
                                    {payslip.download_url && (
                                        <a href={payslip.download_url} target="_blank" rel="noopener noreferrer">
                                            <Button size="sm" variant="secondary">{t('hrms.common.download')}</Button>
                                        </a>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Modal open={!!selected} onClose={() => setSelected(null)} title={selected?.run ? monthLabel(selected.run.period_year, selected.run.period_month) : t('hrms.myPayslips.payslip')} size="lg">
                {selected && (
                    <div className="px-6 py-5 text-sm">
                        <p className="text-gray-500">
                            {t('hrms.myPayslips.drawerMeta', { paid: selected.paid_days, working: selected.working_days, lop: selected.lop_days, ot: selected.ot_minutes })}
                        </p>
                        {lines(t('hrms.myPayslips.earnings'), selected.earnings)}
                        {lines(t('hrms.myPayslips.deductions'), selected.deductions)}
                        {lines(t('hrms.myPayslips.adjustments'), selected.adjustments, 'amount')}
                        <div className="mt-4 rounded-lg border border-gray-200 p-3">
                            <div className="flex justify-between py-0.5"><span className="text-gray-600">{t('hrms.myPayslips.gross')}</span><span className="font-medium">{selected.gross_pay}</span></div>
                            <div className="flex justify-between py-0.5"><span className="text-gray-600">{t('hrms.myPayslips.deductions')}</span><span className="font-medium">{selected.total_deductions}</span></div>
                            <div className="flex justify-between py-0.5"><span className="font-semibold text-gray-900">{t('hrms.myPayslips.net')}</span><span className="font-semibold text-gray-900">{selected.net_pay}</span></div>
                        </div>
                        {selected.download_url && (
                            <div className="mt-4">
                                <a href={selected.download_url} target="_blank" rel="noopener noreferrer">
                                    <Button variant="secondary" size="sm">{t('hrms.myPayslips.downloadPayslip')}</Button>
                                </a>
                            </div>
                        )}
                    </div>
                )}
            </Modal>
        </div>
    );
}
