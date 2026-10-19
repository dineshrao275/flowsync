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

const BLANK = { scope: 'mine', title: '', period: '', start_date: '', end_date: '', key_results: [{ title: '', start_value: '0', target_value: '' }] };

function Bar({ value }) {
    return (
        <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100" role="progressbar" aria-valuenow={value} aria-valuemin={0} aria-valuemax={100}>
            <div className="h-full rounded-full bg-[var(--accent)]" style={{ width: `${value}%` }} />
        </div>
    );
}

/** OKRs: objectives with measurable key results; progress is computed from the numbers. */
export default function Okrs() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.okrs.pageTitle'));
    const toast = useToast();
    const [items, setItems] = useState(null);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(BLANK);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/okrs').then(({ data }) => setItems(data.objectives)).catch(() => setError(t('hrms.okrs.loadFailed')));
    }, [t]);

    useEffect(() => { load(); }, [load]);

    async function submit(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post('/hrms/okrs', {
                ...form,
                key_results: form.key_results.filter((k) => k.title.trim() && k.target_value !== '').map((k) => ({ ...k, start_value: Number(k.start_value || 0), target_value: Number(k.target_value) })),
            });
            toast.success(t('hrms.okrs.created'));
            setOpen(false);
            setForm(BLANK);
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors({ ...fe, form: fe.form || fe.scope || err.response?.data?.message });
        }
    }

    async function update(kr, value) {
        try {
            await api.put(`/hrms/okrs/key-results/${kr.id}`, { current_value: Number(value) });
            load();
        } catch {
            setError(t('hrms.okrs.actionFailed'));
        }
    }

    async function close(o) {
        await api.post(`/hrms/okrs/${o.id}/close`);
        load();
    }

    const setKr = (i, key) => (e) => setForm((f) => ({ ...f, key_results: f.key_results.map((k, j) => (j === i ? { ...k, [key]: e.target.value } : k)) }));

    if (!items) return (error ? <Alert>{error}</Alert> : <ContentSkeleton size="lg" />);

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('hrms.okrs.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('hrms.okrs.subtitle')}</p>
                </div>
                <Button onClick={() => setOpen(true)}>{t('hrms.okrs.new')}</Button>
            </div>
            {error && <Alert>{error}</Alert>}

            {items.length === 0 ? <Card><p className="py-8 text-center text-sm text-gray-500">{t('hrms.okrs.empty')}</p></Card> : items.map((o) => (
                <Card key={o.id}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="font-medium text-gray-900">{o.title} <Badge tone={o.scope === 'company' ? 'accent' : 'info'}>{o.scope === 'company' ? t('hrms.okrs.company') : o.owner?.name}</Badge> {o.status === 'closed' && <Badge tone="neutral">{t('hrms.okrs.closed')}</Badge>}</p>
                            <p className="text-sm text-gray-500">{o.period} · {formatDateOnly(o.start_date)} → {formatDateOnly(o.end_date)}</p>
                        </div>
                        {o.can_edit && <Button size="sm" variant="secondary" onClick={() => close(o)}>{t('hrms.okrs.close')}</Button>}
                    </div>
                    <div className="mt-3 flex items-center gap-3"><Bar value={o.progress} /><span className="w-12 text-right text-sm font-medium text-gray-700">{o.progress}%</span></div>
                    <ul className="mt-3 space-y-2">
                        {o.key_results.map((k) => (
                            <li key={k.id} className="text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-gray-800">{k.title}</span>
                                    <span className="flex items-center gap-2 text-gray-600">
                                        {o.can_edit ? (
                                            <input aria-label={t('hrms.okrs.current', { title: k.title })} type="number" step="any" defaultValue={k.current_value} className="w-24 rounded-md border border-gray-300 px-2 py-1 text-sm" onBlur={(e) => e.target.value !== String(k.current_value) && update(k, e.target.value)} />
                                        ) : <span>{k.current_value}</span>}
                                        <span>/ {k.target_value}{k.unit ? ` ${k.unit}` : ''}</span>
                                        <span className="w-10 text-right text-xs text-gray-500">{k.progress}%</span>
                                    </span>
                                </div>
                                <div className="mt-1"><Bar value={k.progress} /></div>
                            </li>
                        ))}
                    </ul>
                </Card>
            ))}

            <Modal open={open} onClose={() => setOpen(false)} title={t('hrms.okrs.new')} subtitle={t('hrms.okrs.modalHint')}>
                <form onSubmit={submit}>
                    <div className="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-5">
                        {errors.form && <Alert>{errors.form}</Alert>}
                        <div>
                            <label htmlFor="okr-scope" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.okrs.scope')}</label>
                            <select id="okr-scope" className={fieldClass} value={form.scope} onChange={(e) => setForm((f) => ({ ...f, scope: e.target.value }))}>
                                <option value="mine">{t('hrms.okrs.scopeMine')}</option>
                                <option value="company">{t('hrms.okrs.scopeCompany')}</option>
                            </select>
                        </div>
                        <Input label={t('hrms.okrs.title')} value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} error={errors.title} required />
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Input label={t('hrms.okrs.period')} placeholder="Q4 2026" value={form.period} onChange={(e) => setForm((f) => ({ ...f, period: e.target.value }))} error={errors.period} required />
                            <Input label={t('hrms.okrs.start')} type="date" value={form.start_date} onChange={(e) => setForm((f) => ({ ...f, start_date: e.target.value }))} error={errors.start_date} required />
                            <Input label={t('hrms.okrs.end')} type="date" value={form.end_date} onChange={(e) => setForm((f) => ({ ...f, end_date: e.target.value }))} error={errors.end_date} required />
                        </div>
                        <p className="text-sm font-medium text-gray-700">{t('hrms.okrs.keyResults')}</p>
                        {form.key_results.map((k, i) => (
                            <div key={i} className="grid gap-2 sm:grid-cols-6">
                                <div className="sm:col-span-3"><Input aria-label={t('hrms.okrs.krTitle', { n: i + 1 })} placeholder={t('hrms.okrs.krPlaceholder')} value={k.title} onChange={setKr(i, 'title')} /></div>
                                <Input aria-label={t('hrms.okrs.from')} type="number" step="any" value={k.start_value} onChange={setKr(i, 'start_value')} />
                                <Input aria-label={t('hrms.okrs.to')} type="number" step="any" placeholder={t('hrms.okrs.to')} value={k.target_value} onChange={setKr(i, 'target_value')} />
                            </div>
                        ))}
                        <Button type="button" size="sm" variant="ghost" onClick={() => setForm((f) => ({ ...f, key_results: [...f.key_results, { title: '', start_value: '0', target_value: '' }] }))}>{t('hrms.okrs.addKr')}</Button>
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" disabled={!form.title || !form.period || !form.start_date || !form.end_date}>{t('hrms.okrs.create')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
