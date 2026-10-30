import { useCallback, useEffect, useState } from 'react';
import PersonSelect from '../../components/ui/PersonSelect';
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

const TONE = { active: 'warning', completed: 'success', unsuccessful: 'danger', cancelled: 'neutral' };
const BLANK = { employee_id: '', reason: '', start_date: '', end_date: '', milestones: [''] };

/** Performance improvement plans: confidential, dated, with milestones and a written outcome. */
export default function ImprovementPlans() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.pip.pageTitle'));
    const toast = useToast();
    const [plans, setPlans] = useState(null);
    const [people, setPeople] = useState([]);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(BLANK);
    const [errors, setErrors] = useState({});
    const [closing, setClosing] = useState(null);
    const [close, setClose] = useState({ status: 'completed', outcome: '' });
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/improvement-plans').then(({ data }) => setPlans(data.plans)).catch(() => setError(t('hrms.pip.loadFailed')));
    }, [t]);

    useEffect(() => {
        load();
        api.get('/hrms/employees', { params: { per_page: 250 } }).then(({ data }) => setPeople(data.employees || [])).catch(() => {});
    }, [load]);

    async function submit(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post(`/hrms/employees/${form.employee_id}/improvement-plans`, {
                reason: form.reason, start_date: form.start_date, end_date: form.end_date,
                milestones: form.milestones.filter((m) => m.trim()).map((title) => ({ title })),
            });
            toast.success(t('hrms.pip.opened'));
            setOpen(false);
            setForm(BLANK);
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors({ ...fe, form: fe.form || err.response?.data?.message });
        }
    }

    async function toggle(plan, m) {
        try {
            await api.put(`/hrms/improvement-plans/${plan.id}/milestones/${m.id}`, { done: !m.done });
            load();
        } catch (err) {
            setError(fieldErrors(err).form || t('hrms.pip.actionFailed'));
        }
    }

    async function submitClose(e) {
        e.preventDefault();
        try {
            await api.post(`/hrms/improvement-plans/${closing.id}/close`, close);
            toast.success(t('hrms.pip.closed'));
            setClosing(null);
            setClose({ status: 'completed', outcome: '' });
            load();
        } catch (err) {
            setError(fieldErrors(err).outcome || fieldErrors(err).form || t('hrms.pip.actionFailed'));
        }
    }

    if (!plans) return (error ? <Alert>{error}</Alert> : <ContentSkeleton size="lg" />);

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('hrms.pip.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('hrms.pip.subtitle')}</p>
                </div>
                <Button onClick={() => setOpen(true)}>{t('hrms.pip.open')}</Button>
            </div>
            {error && <Alert>{error}</Alert>}

            <Card>
                {plans.length === 0 ? <p className="py-8 text-center text-sm text-gray-500">{t('hrms.pip.empty')}</p> : (
                    <ul className="divide-y divide-gray-100">
                        {plans.map((p) => (
                            <li key={p.id} className="py-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium text-gray-900">{p.employee?.name} <Badge tone={TONE[p.status]}>{t(`hrms.pip.status.${p.status}`)}</Badge></p>
                                        <p className="text-sm text-gray-500">{formatDateOnly(p.start_date)} → {formatDateOnly(p.end_date)}</p>
                                        <p className="mt-1 text-sm text-gray-700">{p.reason}</p>
                                    </div>
                                    {p.can_manage && <Button size="sm" variant="secondary" onClick={() => setClosing(p)}>{t('hrms.pip.close')}</Button>}
                                </div>
                                {p.milestones.length > 0 && (
                                    <ul className="mt-2 space-y-1">
                                        {p.milestones.map((m) => (
                                            <li key={m.id} className="flex items-center gap-2 text-sm">
                                                <input type="checkbox" checked={m.done} disabled={!p.can_manage} onChange={() => toggle(p, m)} aria-label={m.title} />
                                                <span className={m.done ? 'text-gray-400 line-through' : 'text-gray-700'}>{m.title}</span>
                                                {m.due_date && <span className="text-xs text-gray-500">{formatDateOnly(m.due_date)}</span>}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {p.outcome && <p className="mt-2 text-sm text-gray-600">{t('hrms.pip.outcome', { outcome: p.outcome })}</p>}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal open={open} onClose={() => setOpen(false)} title={t('hrms.pip.open')} subtitle={t('hrms.pip.modalHint')}>
                <form onSubmit={submit}>
                    <div className="space-y-4 px-6 py-5">
                        {errors.form && <Alert>{errors.form}</Alert>}
                        <div>
                            <label htmlFor="pip-emp" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.pip.employee')}</label>
                            <PersonSelect id="pip-emp" value={form.employee_id} onChange={(e) => setForm((f) => ({ ...f, employee_id: e.target.value }))} placeholder="—" people={people} />
                        </div>
                        <Input label={t('hrms.pip.reason')} value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} error={errors.reason} required />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input label={t('hrms.pip.start')} type="date" value={form.start_date} onChange={(e) => setForm((f) => ({ ...f, start_date: e.target.value }))} error={errors.start_date} required />
                            <Input label={t('hrms.pip.end')} type="date" value={form.end_date} onChange={(e) => setForm((f) => ({ ...f, end_date: e.target.value }))} error={errors.end_date} required />
                        </div>
                        <div className="space-y-2">
                            <p className="text-sm font-medium text-gray-700">{t('hrms.pip.milestones')}</p>
                            {form.milestones.map((m, i) => (
                                <Input key={i} aria-label={t('hrms.pip.milestone', { n: i + 1 })} value={m} onChange={(e) => setForm((f) => ({ ...f, milestones: f.milestones.map((x, j) => (j === i ? e.target.value : x)) }))} />
                            ))}
                            <Button type="button" size="sm" variant="ghost" onClick={() => setForm((f) => ({ ...f, milestones: [...f.milestones, ''] }))}>{t('hrms.pip.addMilestone')}</Button>
                        </div>
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" disabled={!form.employee_id || !form.reason || !form.start_date || !form.end_date}>{t('hrms.pip.create')}</Button>
                    </div>
                </form>
            </Modal>

            <Modal open={!!closing} onClose={() => setClosing(null)} title={t('hrms.pip.close')}>
                <form onSubmit={submitClose}>
                    <div className="space-y-4 px-6 py-5">
                        <select aria-label={t('hrms.pip.result')} className={fieldClass} value={close.status} onChange={(e) => setClose((c) => ({ ...c, status: e.target.value }))}>
                            {['completed', 'unsuccessful', 'cancelled'].map((s) => <option key={s} value={s}>{t(`hrms.pip.status.${s}`)}</option>)}
                        </select>
                        <Input label={t('hrms.pip.outcomeLabel')} value={close.outcome} onChange={(e) => setClose((c) => ({ ...c, outcome: e.target.value }))} required />
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setClosing(null)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" disabled={!close.outcome.trim()}>{t('hrms.pip.close')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
