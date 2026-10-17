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
import Spinner from '../../components/ui/Spinner';
import Alert from '../../components/ui/Alert';

const TONE = { active: 'info', completed: 'success', cancelled: 'neutral' };
const BLANK = { title: '', focus: '', start_date: '', end_date: '', actions: [''] };

/** Individual development plans: growth goals with dated actions, owned by the employee. */
export default function DevelopmentPlans() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.idp.pageTitle'));
    const toast = useToast();
    const [plans, setPlans] = useState(null);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(BLANK);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/development-plans').then(({ data }) => setPlans(data.plans)).catch(() => setError(t('hrms.idp.loadFailed')));
    }, [t]);

    useEffect(() => { load(); }, [load]);

    async function submit(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post('/hrms/development-plans', { ...form, actions: form.actions.filter((a) => a.trim()).map((title) => ({ title })) });
            toast.success(t('hrms.idp.opened'));
            setOpen(false);
            setForm(BLANK);
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors({ ...fe, form: fe.form || err.response?.data?.message });
        }
    }

    async function tick(plan, a) {
        try {
            await api.put(`/hrms/development-plans/${plan.id}/actions/${a.id}`, { done: !a.done });
            load();
        } catch {
            setError(t('hrms.idp.actionFailed'));
        }
    }

    async function close(plan, status) {
        await api.post(`/hrms/development-plans/${plan.id}/close`, { status });
        load();
    }

    if (!plans) return <div className="flex justify-center py-12">{error ? <Alert>{error}</Alert> : <Spinner />}</div>;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('hrms.idp.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('hrms.idp.subtitle')}</p>
                </div>
                <Button onClick={() => setOpen(true)}>{t('hrms.idp.open')}</Button>
            </div>
            {error && <Alert>{error}</Alert>}

            <Card>
                {plans.length === 0 ? <p className="py-8 text-center text-sm text-gray-500">{t('hrms.idp.empty')}</p> : (
                    <ul className="divide-y divide-gray-100">
                        {plans.map((p) => (
                            <li key={p.id} className="py-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium text-gray-900">{p.title} <Badge tone={TONE[p.status]}>{t(`hrms.idp.status.${p.status}`)}</Badge></p>
                                        <p className="text-sm text-gray-500">{p.employee?.name} · {formatDateOnly(p.start_date)} → {formatDateOnly(p.end_date)}</p>
                                        {p.focus && <p className="mt-1 text-sm text-gray-700">{p.focus}</p>}
                                    </div>
                                    {p.status === 'active' && (
                                        <div className="flex gap-2">
                                            <Button size="sm" variant="secondary" onClick={() => close(p, 'cancelled')}>{t('hrms.idp.cancel')}</Button>
                                            <Button size="sm" onClick={() => close(p, 'completed')}>{t('hrms.idp.complete')}</Button>
                                        </div>
                                    )}
                                </div>
                                {p.actions.length > 0 && (
                                    <ul className="mt-2 space-y-1">
                                        {p.actions.map((a) => (
                                            <li key={a.id} className="flex items-center gap-2 text-sm">
                                                <input type="checkbox" checked={a.done} disabled={p.status !== 'active'} onChange={() => tick(p, a)} aria-label={a.title} />
                                                <span className={a.done ? 'text-gray-400 line-through' : 'text-gray-700'}>{a.title}</span>
                                                {a.due_date && <span className="text-xs text-gray-500">{formatDateOnly(a.due_date)}</span>}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal open={open} onClose={() => setOpen(false)} title={t('hrms.idp.open')} subtitle={t('hrms.idp.modalHint')}>
                <form onSubmit={submit}>
                    <div className="space-y-4 px-6 py-5">
                        {errors.form && <Alert>{errors.form}</Alert>}
                        <Input label={t('hrms.idp.title')} value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} error={errors.title} required />
                        <Input label={t('hrms.idp.focus')} value={form.focus} onChange={(e) => setForm((f) => ({ ...f, focus: e.target.value }))} />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input label={t('hrms.idp.start')} type="date" value={form.start_date} onChange={(e) => setForm((f) => ({ ...f, start_date: e.target.value }))} error={errors.start_date} required />
                            <Input label={t('hrms.idp.end')} type="date" value={form.end_date} onChange={(e) => setForm((f) => ({ ...f, end_date: e.target.value }))} error={errors.end_date} required />
                        </div>
                        <div className="space-y-2">
                            <p className="text-sm font-medium text-gray-700">{t('hrms.idp.actions')}</p>
                            {form.actions.map((a, i) => (
                                <Input key={i} aria-label={t('hrms.idp.action', { n: i + 1 })} value={a} onChange={(e) => setForm((f) => ({ ...f, actions: f.actions.map((x, j) => (j === i ? e.target.value : x)) }))} />
                            ))}
                            <Button type="button" size="sm" variant="ghost" onClick={() => setForm((f) => ({ ...f, actions: [...f.actions, ''] }))}>{t('hrms.idp.addAction')}</Button>
                        </div>
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" disabled={!form.title || !form.start_date || !form.end_date}>{t('hrms.idp.create')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
