import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { formatMinutes } from '../../utils/time';
import i18n, { intlTag } from '../../i18n';
import { useAuth } from '../../context/AuthContext';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import Input from '../ui/Input';
import { fieldClass } from '../ui/fieldStyles';
import { useConfirm } from '../ui/ConfirmDialog';

function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function formatRange(log, t) {
    const start = new Date(log.started_at);
    const end = log.ended_at ? new Date(log.ended_at) : null;
    const tag = intlTag(i18n.language);
    const date = start.toLocaleDateString(tag, { month: 'short', day: 'numeric' });
    const time = (d) =>
        d.toLocaleTimeString(tag, { hour: 'numeric', minute: '2-digit' });
    return `${date} · ${time(start)}${end ? ` – ${time(end)}` : ` – ${t('tms.worklogs.now')}`}`;
}

export default function WorkLogPanel({ task, projectId, canLog, canManage }) {
    const { t } = useTranslation();
    const { user } = useAuth();
    const confirm = useConfirm();
    const [logs, setLogs] = useState([]);
    const [totals, setTotals] = useState({ total_minutes: 0, estimate_minutes: null, remaining_minutes: null, logs_count: 0 });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const [form, setForm] = useState({ started_at: '', ended_at: '', description: '' });
    const [editingId, setEditingId] = useState(null);

    const base = `/projects/${projectId}/tasks/${task.id}/work-logs`;

    useEffect(() => {
        let active = true;
        setLoading(true);
        api.get(base)
            .then(({ data }) => {
                if (!active) return;
                setLogs(data.work_logs);
                setTotals(data.totals);
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [task.id]);

    function load() {
        return api.get(base).then(({ data }) => {
            setLogs(data.work_logs);
            setTotals(data.totals);
        });
    }

    function startEdit(log) {
        setEditingId(log.id);
        setErrors({});
        setForm({
            started_at: toLocalInput(log.started_at),
            ended_at: toLocalInput(log.ended_at),
            description: log.description || '',
        });
    }

    function reset() {
        setEditingId(null);
        setErrors({});
        setForm({ started_at: '', ended_at: '', description: '' });
    }

    function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        const payload = { ...form, description: form.description || null };
        const req = editingId ? api.put(`${base}/${editingId}`, payload) : api.post(base, payload);
        req.then(() => {
            reset();
            return load();
        })
            .catch((err) => setErrors(fieldErrors(err)))
            .finally(() => setSaving(false));
    }

    async function remove(log) {
        if (!(await confirm({ title: t('tms.worklogs.removeTitle'), message: t('tms.worklogs.removeMessage'), variant: 'danger' }))) return;
        api.delete(`${base}/${log.id}`).then(() => load()).catch((e) => setErrors(fieldErrors(e)));
    }

    const canEditLog = (log) => (canLog && log.user?.id === user?.id) || canManage;

    return (
        <div className="space-y-5">
            {canLog ? (
                <form onSubmit={submit} className="space-y-3 rounded-lg border border-gray-200 p-4">
                    <div className="flex items-center justify-between">
                        <p className="text-sm font-semibold text-gray-900">
                            {editingId ? t('tms.worklogs.editTitle') : t('tms.worklogs.createTitle')}
                        </p>
                        {editingId && (
                            <Button type="button" size="sm" variant="ghost" onClick={reset}>
                                {t('tms.worklogs.cancel')}
                            </Button>
                        )}
                    </div>
                    {errors.form && <Alert>{errors.form}</Alert>}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input
                            label={t('tms.worklogs.startedAt')}
                            type="datetime-local"
                            value={form.started_at}
                            onChange={(e) => setForm((f) => ({ ...f, started_at: e.target.value }))}
                            error={errors.started_at}
                            required
                        />
                        <Input
                            label={t('tms.worklogs.endedAt')}
                            type="datetime-local"
                            value={form.ended_at}
                            onChange={(e) => setForm((f) => ({ ...f, ended_at: e.target.value }))}
                            error={errors.ended_at}
                        />
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.worklogs.description')}</label>
                        <input
                            type="text"
                            className={fieldClass}
                            placeholder={t('tms.worklogs.descriptionPlaceholder')}
                            value={form.description}
                            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                        />
                        {errors.description && <p className="mt-1.5 text-sm text-red-600">{errors.description}</p>}
                    </div>
                    <Button type="submit" loading={saving}>
                        {editingId ? t('tms.worklogs.save') : t('tms.worklogs.add')}
                    </Button>
                </form>
            ) : null}

            <div className="grid grid-cols-3 gap-3 text-center">
                <div className="rounded-lg border border-gray-100 px-3 py-2">
                    <p className="text-lg font-bold text-gray-900">{formatMinutes(totals.total_minutes)}</p>
                    <p className="text-xs text-gray-500">{t('tms.worklogs.totalLogged')}</p>
                </div>
                <div className="rounded-lg border border-gray-100 px-3 py-2">
                    <p className="text-lg font-bold text-gray-900">{formatMinutes(totals.estimate_minutes)}</p>
                    <p className="text-xs text-gray-500">{t('tms.worklogs.estimate')}</p>
                </div>
                <div className="rounded-lg border border-gray-100 px-3 py-2">
                    <p className="text-lg font-bold text-gray-900">{formatMinutes(totals.remaining_minutes)}</p>
                    <p className="text-xs text-gray-500">{t('tms.worklogs.remaining')}</p>
                </div>
            </div>

            {loading ? (
                <div className="flex justify-center py-8">
                    <Spinner />
                </div>
            ) : logs.length === 0 ? (
                <p className="py-6 text-center text-sm text-gray-500">{t('tms.worklogs.empty')}</p>
            ) : (
                <ul className="divide-y divide-gray-100">
                    {logs.map((log) => (
                        <li key={log.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-gray-900">
                                    {log.user?.name || t('tms.worklogs.unknown')}{' '}
                                    {log.user?.id === user?.id && <span className="text-gray-400">{t('tms.worklogs.you')}</span>}
                                </p>
                                <p className="text-xs text-gray-500">{formatRange(log, t)}</p>
                                {log.description && <p className="mt-0.5 text-sm text-gray-600">{log.description}</p>}
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="rounded-full bg-[var(--accent-soft)] px-2.5 py-1 text-xs font-semibold text-[var(--accent-soft-text)]">
                                    {formatMinutes(log.effective_minutes ?? log.duration_minutes)}
                                </span>
                                {canEditLog(log) && (
                                    <div className="flex gap-1">
                                        <Button size="sm" variant="ghost" onClick={() => startEdit(log)}>
                                            {t('tms.worklogs.edit')}
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => remove(log)}>
                                            {t('tms.worklogs.delete')}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}