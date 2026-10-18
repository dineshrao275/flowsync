import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../../components/ui/ConfirmDialog';
import usePageTitle from '../../hooks/usePageTitle';
import { formatDateOnly } from '../../utils/format';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Alert from '../../components/ui/Alert';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Badge from '../../components/ui/Badge';
import Textarea from '../../components/ui/Textarea';

const TONE = { pending: 'warning', approved: 'success', rejected: 'danger', withdrawn: 'neutral' };

function StatusBadge({ r }) {
    const { t } = useTranslation();
    return <Badge tone={TONE[r.status]}>{t(`hrms.resignation.status.${r.status}`)}</Badge>;
}

function FileForm({ onFiled }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [form, setForm] = useState({ requested_last_day: '', reason: '', notice_period_days: '' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await api.post('/hrms/resignations', {
                requested_last_day: form.requested_last_day,
                reason: form.reason,
                notice_period_days: form.notice_period_days === '' ? null : Number(form.notice_period_days),
            });
            toast.success(t('hrms.resignation.filed'));
            setForm({ requested_last_day: '', reason: '', notice_period_days: '' });
            onFiled();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <p className="text-sm text-gray-600">{t('hrms.resignation.intro')}</p>
            {errors.form && <Alert>{errors.form}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
                <Input label={t('hrms.resignation.lastDay')} type="date" value={form.requested_last_day} onChange={(e) => setForm((f) => ({ ...f, requested_last_day: e.target.value }))} error={errors.requested_last_day} required />
                <Input label={t('hrms.resignation.notice')} type="number" min="0" max="365" value={form.notice_period_days} onChange={(e) => setForm((f) => ({ ...f, notice_period_days: e.target.value }))} error={errors.notice_period_days} />
            </div>
            <Textarea label={t('hrms.resignation.reason')} rows={4} value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} error={errors.reason} required />
            <Button type="submit" loading={saving} disabled={!form.requested_last_day || !form.reason.trim()}>{t('hrms.resignation.submit')}</Button>
        </form>
    );
}

/** My resignation (file, track, withdraw) plus, for approvers, the ones waiting for a decision. */
export default function Resignation() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.resignation.pageTitle'));
    const { can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const [data, setData] = useState(null);
    const [note, setNote] = useState({});
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/resignations').then(({ data: res }) => setData(res)).catch(() => setError(t('hrms.resignation.loadFailed')));
    }, [t]);

    useEffect(load, [load]);

    async function act(r, action) {
        setError(null);
        try {
            await api.post(`/hrms/resignations/${r.id}/${action}`, action === 'withdraw' ? {} : { note: note[r.id] || '' });
            toast.success(t(`hrms.resignation.done.${action}`));
            load();
        } catch (err) {
            setError(fieldErrors(err).decision_note || fieldErrors(err).note || fieldErrors(err).form || fieldErrors(err).approval || t('hrms.resignation.actionFailed'));
        }
    }

    async function withdraw(r) {
        if (await confirm({ title: t('hrms.resignation.withdrawTitle'), message: t('hrms.resignation.withdrawMessage'), variant: 'danger' })) act(r, 'withdraw');
    }

    if (!data) return (error ? <Alert>{error}</Alert> : <ContentSkeleton size="lg" />);

    const open = data.mine.find((r) => r.status === 'pending');

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('hrms.resignation.pageTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('hrms.resignation.subtitle')}</p>
            </div>
            {error && <Alert>{error}</Alert>}

            <Card title={t('hrms.resignation.mineTitle')}>
                {!open && <FileForm onFiled={load} />}
                {data.mine.length > 0 && (
                    <ul className={`divide-y divide-gray-100 ${open ? '' : 'mt-6 border-t border-gray-100'}`}>
                        {data.mine.map((r) => (
                            <li key={r.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                <div>
                                    <p className="text-sm font-medium text-gray-900">{t('hrms.resignation.lastDayIs', { date: formatDateOnly(r.requested_last_day) })} <StatusBadge r={r} /></p>
                                    <p className="mt-0.5 text-sm text-gray-600">{r.reason}</p>
                                    {r.decision_note && <p className="mt-0.5 text-xs text-gray-500">{t('hrms.resignation.note', { note: r.decision_note })}</p>}
                                    {r.status === 'approved' && (
                                        <Link className="mr-3 text-xs text-[var(--accent-soft-text)] hover:underline" to="/hrms/exit-interview">{t('hrms.resignation.exitInterview')}</Link>
                                    )}
                                    {r.status === 'approved' && can('hrms.offboarding.view') && r.offboarding_case_id && (
                                        <Link className="text-xs text-[var(--accent-soft-text)] hover:underline" to={`/hrms/offboarding/cases/${r.offboarding_case_id}`}>{t('hrms.resignation.openCase')}</Link>
                                    )}
                                </div>
                                {r.status === 'pending' && <Button size="sm" variant="secondary" onClick={() => withdraw(r)}>{t('hrms.resignation.withdraw')}</Button>}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {data.to_decide.length > 0 && (
                <Card title={t('hrms.resignation.decideTitle')} subtitle={t('hrms.resignation.decideSubtitle')}>
                    <ul className="space-y-3">
                        {data.to_decide.map((r) => (
                            <li key={r.id} className="rounded-lg border border-gray-200 p-4">
                                <p className="font-medium text-gray-900">{r.employee?.name}</p>
                                <p className="text-sm text-gray-600">{t('hrms.resignation.lastDayIs', { date: formatDateOnly(r.requested_last_day) })}</p>
                                <p className="mt-1 text-sm text-gray-700">{r.reason}</p>
                                <div className="mt-3 flex flex-wrap items-end gap-2">
                                    <div className="w-64"><Input label={t('hrms.resignation.noteLabel')} compact value={note[r.id] || ''} onChange={(e) => setNote((n) => ({ ...n, [r.id]: e.target.value }))} /></div>
                                    <Button variant="secondary" onClick={() => act(r, 'reject')}>{t('hrms.resignation.reject')}</Button>
                                    <Button onClick={() => act(r, 'approve')}>{t('hrms.resignation.approve')}</Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {data.all && data.all.length > 0 && (
                <Card title={t('hrms.resignation.allTitle')}>
                    <ul className="divide-y divide-gray-100">
                        {data.all.map((r) => (
                            <li key={r.id} className="flex items-center justify-between py-2 text-sm">
                                <span><span className="font-medium text-gray-900">{r.employee?.name}</span> · {formatDateOnly(r.requested_last_day)}</span>
                                <StatusBadge r={r} />
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </div>
    );
}
