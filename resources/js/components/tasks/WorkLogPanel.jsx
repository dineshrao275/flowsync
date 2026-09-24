import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import { formatMinutes } from '../../utils/time';
import { useAuth } from '../../context/AuthContext';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import { fieldClass } from '../ui/fieldStyles';

function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function formatRange(log) {
    const start = new Date(log.started_at);
    const end = log.ended_at ? new Date(log.ended_at) : null;
    const date = start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    const time = (d) =>
        d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    return `${date} · ${time(start)}${end ? ` – ${time(end)}` : ' – now'}`;
}

export default function WorkLogPanel({ task, projectId, canLog, canManage }) {
    const { user } = useAuth();
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

    function remove(log) {
        if (!window.confirm('Remove this work log? This cannot be undone.')) return;
        api.delete(`${base}/${log.id}`).then(() => load()).catch((e) => setErrors(fieldErrors(e)));
    }

    const canEditLog = (log) => (canLog && log.user?.id === user?.id) || canManage;

    return (
        <div className="space-y-5">
            {canLog ? (
                <form onSubmit={submit} className="space-y-3 rounded-lg border border-gray-200 p-4">
                    <div className="flex items-center justify-between">
                        <p className="text-sm font-semibold text-gray-900">
                            {editingId ? 'Edit work log' : 'Log time'}
                        </p>
                        {editingId && (
                            <Button type="button" size="sm" variant="ghost" onClick={reset}>
                                Cancel
                            </Button>
                        )}
                    </div>
                    {errors.form && <Alert>{errors.form}</Alert>}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">Started at</label>
                            <input
                                type="datetime-local"
                                className={fieldClass}
                                value={form.started_at}
                                onChange={(e) => setForm((f) => ({ ...f, started_at: e.target.value }))}
                                required
                            />
                            {errors.started_at && <p className="mt-1.5 text-sm text-red-600">{errors.started_at}</p>}
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">Ended at</label>
                            <input
                                type="datetime-local"
                                className={fieldClass}
                                value={form.ended_at}
                                onChange={(e) => setForm((f) => ({ ...f, ended_at: e.target.value }))}
                            />
                            {errors.ended_at && <p className="mt-1.5 text-sm text-red-600">{errors.ended_at}</p>}
                        </div>
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Description (optional)</label>
                        <input
                            type="text"
                            className={fieldClass}
                            placeholder="What did you work on?"
                            value={form.description}
                            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                        />
                        {errors.description && <p className="mt-1.5 text-sm text-red-600">{errors.description}</p>}
                    </div>
                    <Button type="submit" loading={saving}>
                        {editingId ? 'Save changes' : 'Add work log'}
                    </Button>
                </form>
            ) : null}

            <div className="grid grid-cols-3 gap-3 text-center">
                <div className="rounded-lg border border-gray-100 px-3 py-2">
                    <p className="text-lg font-bold text-gray-900">{formatMinutes(totals.total_minutes)}</p>
                    <p className="text-xs text-gray-500">Logged</p>
                </div>
                <div className="rounded-lg border border-gray-100 px-3 py-2">
                    <p className="text-lg font-bold text-gray-900">{formatMinutes(totals.estimate_minutes)}</p>
                    <p className="text-xs text-gray-500">Estimate</p>
                </div>
                <div className="rounded-lg border border-gray-100 px-3 py-2">
                    <p className="text-lg font-bold text-gray-900">{formatMinutes(totals.remaining_minutes)}</p>
                    <p className="text-xs text-gray-500">Remaining</p>
                </div>
            </div>

            {loading ? (
                <div className="flex justify-center py-8">
                    <Spinner />
                </div>
            ) : logs.length === 0 ? (
                <p className="py-6 text-center text-sm text-gray-500">No work logged yet.</p>
            ) : (
                <ul className="divide-y divide-gray-100">
                    {logs.map((log) => (
                        <li key={log.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-gray-900">
                                    {log.user?.name || 'Unknown'}{' '}
                                    {log.user?.id === user?.id && <span className="text-gray-400">(you)</span>}
                                </p>
                                <p className="text-xs text-gray-500">{formatRange(log)}</p>
                                {log.description && <p className="mt-0.5 text-sm text-gray-600">{log.description}</p>}
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                    {formatMinutes(log.effective_minutes ?? log.duration_minutes)}
                                </span>
                                {canEditLog(log) && (
                                    <div className="flex gap-1">
                                        <Button size="sm" variant="ghost" onClick={() => startEdit(log)}>
                                            Edit
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => remove(log)}>
                                            Delete
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