import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import usePageTitle from '../../hooks/usePageTitle';
import { formatDateOnly } from '../../utils/format';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Alert from '../../components/ui/Alert';
import { fieldClass } from '../../components/ui/fieldStyles';

const TONE = { pending: 'warning', applied: 'success', rejected: 'danger', withdrawn: 'neutral' };
const BLANK = { counterparty_employee_id: '', swap_date: '', reason: '' };

/** Trade a shift with a colleague for one day: they agree, your manager approves, the roster updates. */
export default function ShiftSwaps() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.swaps.pageTitle'));
    const toast = useToast();
    const [swaps, setSwaps] = useState(null);
    const [people, setPeople] = useState([]);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(BLANK);
    const [errors, setErrors] = useState({});
    const [note, setNote] = useState({});
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/shift-swaps').then(({ data }) => setSwaps(data.swaps)).catch(() => setError(t('hrms.swaps.loadFailed')));
    }, [t]);

    useEffect(() => {
        load();
        api.get('/hrms/employees', { params: { per_page: 100 } }).then(({ data }) => setPeople(data.employees || [])).catch(() => {});
    }, [load]);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await api.post('/hrms/shift-swaps', { ...form, counterparty_employee_id: Number(form.counterparty_employee_id), reason: form.reason || null });
            toast.success(t('hrms.swaps.requested'));
            setOpen(false);
            setForm(BLANK);
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors({ ...fe, form: fe.form || err.response?.data?.message });
        } finally {
            setSaving(false);
        }
    }

    async function act(s, action) {
        setError(null);
        try {
            await api.post(`/hrms/shift-swaps/${s.id}/${action}`, action === 'withdraw' ? {} : { note: note[s.id] || '' });
            toast.success(t(`hrms.swaps.done.${action}`));
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setError(fe.decision_note || fe.note || fe.form || fe.approval || t('hrms.swaps.actionFailed'));
            load();
        }
    }

    if (!swaps) return (error ? <Alert>{error}</Alert> : <ContentSkeleton size="lg" />);

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('hrms.swaps.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('hrms.swaps.subtitle')}</p>
                </div>
                <Button onClick={() => setOpen(true)}>{t('hrms.swaps.request')}</Button>
            </div>
            {error && <Alert>{error}</Alert>}

            <Card>
                {swaps.length === 0 ? <p className="py-8 text-center text-sm text-gray-500">{t('hrms.swaps.empty')}</p> : (
                    <ul className="divide-y divide-gray-100">
                        {swaps.map((s) => (
                            <li key={s.id} className="py-3">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium text-gray-900">{t('hrms.swaps.between', { a: s.requester?.name, b: s.counterparty?.name })} <Badge tone={TONE[s.status]}>{t(`hrms.swaps.status.${s.status}`)}</Badge></p>
                                        <p className="text-sm text-gray-500">{formatDateOnly(s.swap_date)}</p>
                                        {s.reason && <p className="text-sm text-gray-600">{s.reason}</p>}
                                        {s.decision_note && <p className="text-xs text-gray-500">{t('hrms.swaps.note', { note: s.decision_note })}</p>}
                                    </div>
                                    {s.status === 'pending' && !s.can_decide && <Button size="sm" variant="ghost" onClick={() => act(s, 'withdraw')}>{t('hrms.swaps.withdraw')}</Button>}
                                </div>
                                {s.can_decide && (
                                    <div className="mt-3 flex flex-wrap items-end gap-2">
                                        <div className="w-64"><Input label={t('hrms.swaps.noteLabel')} compact value={note[s.id] || ''} onChange={(e) => setNote((n) => ({ ...n, [s.id]: e.target.value }))} /></div>
                                        <Button variant="secondary" onClick={() => act(s, 'reject')}>{t('hrms.swaps.reject')}</Button>
                                        <Button onClick={() => act(s, 'approve')}>{t('hrms.swaps.approve')}</Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal open={open} onClose={() => setOpen(false)} title={t('hrms.swaps.request')} subtitle={t('hrms.swaps.modalHint')}>
                <form onSubmit={submit}>
                    <div className="space-y-4 px-6 py-5">
                        {errors.form && <Alert>{errors.form}</Alert>}
                        <div>
                            <label htmlFor="swap-with" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.swaps.with')}</label>
                            <select id="swap-with" className={fieldClass} value={form.counterparty_employee_id} onChange={(e) => setForm((f) => ({ ...f, counterparty_employee_id: e.target.value }))}>
                                <option value="">—</option>
                                {people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                            {errors.counterparty_employee_id && <p className="mt-1 text-xs text-red-600">{errors.counterparty_employee_id}</p>}
                        </div>
                        <Input label={t('hrms.swaps.date')} type="date" value={form.swap_date} onChange={(e) => setForm((f) => ({ ...f, swap_date: e.target.value }))} error={errors.swap_date} required />
                        <Input label={t('hrms.swaps.reason')} value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} maxLength={500} />
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" loading={saving} disabled={!form.counterparty_employee_id || !form.swap_date}>{t('hrms.swaps.send')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
