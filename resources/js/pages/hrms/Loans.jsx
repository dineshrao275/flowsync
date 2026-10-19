import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import usePageTitle from '../../hooks/usePageTitle';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Alert from '../../components/ui/Alert';
import { fieldClass } from '../../components/ui/fieldStyles';

const TONE = { pending: 'warning', active: 'info', closed: 'success', rejected: 'danger', cancelled: 'neutral' };
const now = new Date();
const BLANK = { kind: 'advance', principal: '', installments: '3', first_year: String(now.getFullYear()), first_month: String(now.getMonth() + 2 > 12 ? 1 : now.getMonth() + 2), reason: '' };

/** Loans and salary advances repaid through payroll in equal monthly instalments. */
export default function Loans() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.loans.pageTitle'));
    const toast = useToast();
    const [loans, setLoans] = useState(null);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(BLANK);
    const [errors, setErrors] = useState({});
    const [note, setNote] = useState({});
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/loans').then(({ data }) => setLoans(data.loans)).catch(() => setError(t('hrms.loans.loadFailed')));
    }, [t]);

    useEffect(() => { load(); }, [load]);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    async function submit(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post('/hrms/loans', { ...form, installments: Number(form.installments), first_year: Number(form.first_year), first_month: Number(form.first_month), reason: form.reason || null });
            toast.success(t('hrms.loans.requested'));
            setOpen(false);
            setForm(BLANK);
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors({ ...fe, form: fe.form || err.response?.data?.message });
        }
    }

    async function act(l, action) {
        setError(null);
        try {
            await api.post(`/hrms/loans/${l.id}/${action}`, ['approve', 'reject'].includes(action) ? { note: note[l.id] || '' } : {});
            toast.success(t(`hrms.loans.done.${action}`));
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setError(fe.decision_note || fe.note || fe.form || fe.approval || t('hrms.loans.actionFailed'));
        }
    }

    if (!loans) return (error ? <Alert>{error}</Alert> : <ContentSkeleton size="lg" />);

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('hrms.loans.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('hrms.loans.subtitle')}</p>
                </div>
                <Button onClick={() => setOpen(true)}>{t('hrms.loans.request')}</Button>
            </div>
            {error && <Alert>{error}</Alert>}

            <Card>
                {loans.length === 0 ? <p className="py-8 text-center text-sm text-gray-500">{t('hrms.loans.empty')}</p> : (
                    <ul className="divide-y divide-gray-100">
                        {loans.map((l) => {
                            const paid = l.schedule.filter((s) => s.status === 'paid').length;

                            return (
                                <li key={l.id} className="py-3">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-medium text-gray-900">{l.employee?.name} · {t(`hrms.loans.kinds.${l.kind}`)} {l.principal} <Badge tone={TONE[l.status]}>{t(`hrms.loans.status.${l.status}`)}</Badge></p>
                                            <p className="text-sm text-gray-500">{t('hrms.loans.terms', { n: l.installments, amount: l.installment_amount })}{l.status === 'active' ? ` · ${t('hrms.loans.progress', { paid, total: l.installments, balance: l.balance })}` : ''}</p>
                                            {l.reason && <p className="text-sm text-gray-600">{l.reason}</p>}
                                            {l.decision_note && <p className="text-xs text-gray-500">{t('hrms.loans.note', { note: l.decision_note })}</p>}
                                        </div>
                                        <div className="flex gap-2">
                                            {l.status === 'pending' && !l.can_decide && <Button size="sm" variant="ghost" onClick={() => act(l, 'withdraw')}>{t('hrms.loans.withdraw')}</Button>}
                                            {l.can_stop && <Button size="sm" variant="secondary" onClick={() => act(l, 'stop')}>{t('hrms.loans.stop')}</Button>}
                                        </div>
                                    </div>
                                    {l.can_decide && (
                                        <div className="mt-3 flex flex-wrap items-end gap-2">
                                            <div className="w-64"><Input label={t('hrms.loans.noteLabel')} compact value={note[l.id] || ''} onChange={(e) => setNote((n) => ({ ...n, [l.id]: e.target.value }))} /></div>
                                            <Button variant="secondary" onClick={() => act(l, 'reject')}>{t('hrms.loans.reject')}</Button>
                                            <Button onClick={() => act(l, 'approve')}>{t('hrms.loans.approve')}</Button>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>

            <Modal open={open} onClose={() => setOpen(false)} title={t('hrms.loans.request')} subtitle={t('hrms.loans.modalHint')}>
                <form onSubmit={submit}>
                    <div className="space-y-4 px-6 py-5">
                        {errors.form && <Alert>{errors.form}</Alert>}
                        <div>
                            <label htmlFor="loan-kind" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.loans.kind')}</label>
                            <select id="loan-kind" className={fieldClass} value={form.kind} onChange={set('kind')}>
                                {['advance', 'loan'].map((k) => <option key={k} value={k}>{t(`hrms.loans.kinds.${k}`)}</option>)}
                            </select>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input label={t('hrms.loans.amount')} type="number" min="0" step="0.01" value={form.principal} onChange={set('principal')} error={errors.principal} required />
                            <Input label={t('hrms.loans.installments')} type="number" min="1" max="60" value={form.installments} onChange={set('installments')} error={errors.installments} required />
                            <Input label={t('hrms.loans.firstMonth')} type="number" min="1" max="12" value={form.first_month} onChange={set('first_month')} error={errors.first_month} required />
                            <Input label={t('hrms.loans.firstYear')} type="number" value={form.first_year} onChange={set('first_year')} error={errors.first_year} required />
                        </div>
                        <Input label={t('hrms.loans.reason')} value={form.reason} onChange={set('reason')} maxLength={500} />
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" disabled={!form.principal}>{t('hrms.loans.send')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
