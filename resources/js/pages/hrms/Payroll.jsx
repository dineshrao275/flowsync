import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import { usePrompt } from '../../components/ui/ConfirmDialog';
import Modal from '../../components/ui/Modal';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { monthLabel, runStepHint } from '../../utils/payroll';

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
    const { t } = useTranslation();
    usePageTitle(t('hrms.payroll.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const prompt = usePrompt();

    const [runs, setRuns] = useState(null);
    const [error, setError] = useState(null);
    const [modal, setModal] = useState(false);
    const [form, setForm] = useState(emptyRun);
    const [formErrors, setFormErrors] = useState({});
    const [acting, setActing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: t('hrms.payroll.pageTitle') }]);
    }, [setCrumbs, t]);

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
            .catch(fail(t('hrms.payroll.loadFailed')));
    }, [fail, t]);

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
            toast.success(t('hrms.payroll.opened'));
            setModal(false);
            setForm(emptyRun);
            load();
        } catch (err) {
            setFormErrors(fieldErrors(err));
        }
    }

    async function transition(run, action, done, payload = {}) {
        setActing(`${run.id}:${action}`);

        try {
            await api.post(`/hrms/payroll/runs/${run.id}/${action}`, payload);
            toast.success(done);
            load();
        } catch {
            setError(t('hrms.payroll.transitionRefused'));
        } finally {
            setActing(null);
        }
    }

    async function reopen(run) {
        const reason = await prompt({
            title: t('hrms.payroll.reopenTitle'),
            message: t('hrms.payroll.reopenMessage'),
            placeholder: t('hrms.payroll.reopenPlaceholder'),
            required: true,
            confirmLabel: t('hrms.payroll.reopenConfirm'),
            variant: 'danger',
        });

        if (reason === null) return;

        transition(run, 'reopen', t('hrms.payroll.reopened'), { reason });
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

        const reopenButton = (
            <Button key="reopen" size="sm" variant="ghost" disabled={busy} onClick={() => reopen(run)}>
                {t('hrms.payroll.reopen')}
            </Button>
        );

        switch (run.status) {
            case 'draft':
            case 'review':
                return [button('calculate', t('hrms.payroll.calculate'), t('hrms.payroll.calculated'), 'primary'), run.status === 'review' && button('approve', t('hrms.payroll.approve'), t('hrms.payroll.approved'))];
            case 'approved':
                return [button('publish', t('hrms.payroll.publish'), t('hrms.payroll.published'), 'primary'), reopenButton];
            case 'processing':
                return [button('mark-paid', t('hrms.payroll.markPaid'), t('hrms.payroll.markedPaid'), 'primary'), reopenButton];
            case 'paid':
                return [button('lock', t('hrms.payroll.lock'), t('hrms.payroll.locked'), 'warning'), reopenButton];
            default:
                return [];
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.payroll.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {runs ? (runs.length === 1 ? t('hrms.payroll.runCountOne') : t('hrms.payroll.runCount', { count: runs.length })) : '—'}
                    </p>
                </div>
                <Button onClick={() => setModal(true)}>{t('hrms.payroll.openRun')}</Button>
            </div>

            {error && <Alert>{error}</Alert>}

            {!runs ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : runs.length === 0 ? (
                <EmptyState title={t('hrms.payroll.emptyTitle')} hint={t('hrms.payroll.emptyHint')} />
            ) : (
                <Card dense>
                    <Table>
                        <thead>
                            <tr><Th>{t('hrms.payroll.period')}</Th><Th>{t('hrms.payroll.payDate')}</Th><Th>{t('hrms.payroll.status')}</Th><Th>{t('hrms.payroll.people')}</Th><Th>{t('hrms.payroll.netPay')}</Th><Th><span className="sr-only">{t('hrms.common.actions')}</span></Th></tr>
                        </thead>
                        <tbody>
                            {runs.map((run) => (
                                <tr key={run.id}>
                                    <Td>
                                        <Link to={`/hrms/payroll/runs/${run.id}`} className="font-medium text-[var(--accent-soft-text)] hover:underline">
                                            {monthLabel(run.period_year, run.period_month)}
                                        </Link>
                                        <span className="ml-2 text-xs text-gray-400">{runStepHint(t, run.status)}</span>
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

            <Modal open={modal} onClose={() => setModal(false)} title={t('hrms.payroll.modalTitle')} subtitle={t('hrms.payroll.modalSubtitle')}>
                <form onSubmit={openRun} className="grid gap-3 px-6 py-5 sm:grid-cols-2">
                    <Input label={t('hrms.payroll.year')} value={form.period_year} error={formErrors.period_year} onChange={(e) => setForm({ ...form, period_year: e.target.value })} required />
                    <Input label={t('hrms.payroll.month')} value={form.period_month} error={formErrors.period_month} onChange={(e) => setForm({ ...form, period_month: e.target.value })} required />
                    <Input label={t('hrms.payroll.periodStart')} type="date" value={form.pay_period_start} error={formErrors.pay_period_start} onChange={(e) => setForm({ ...form, pay_period_start: e.target.value })} required />
                    <Input label={t('hrms.payroll.periodEnd')} type="date" value={form.pay_period_end} error={formErrors.pay_period_end} onChange={(e) => setForm({ ...form, pay_period_end: e.target.value })} required />
                    <Input label={t('hrms.payroll.payDate')} type="date" value={form.pay_date} error={formErrors.pay_date} onChange={(e) => setForm({ ...form, pay_date: e.target.value })} required />
                    <Input label={t('hrms.payroll.notes')} value={form.notes} error={formErrors.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                    <div className="sm:col-span-2"><Button type="submit">{t('hrms.payroll.submit')}</Button></div>
                </form>
            </Modal>
        </div>
    );
}
