import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import api from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Card from '../ui/Card';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import ContentSkeleton from '../ui/ContentSkeleton';
import Checkbox from '../ui/Checkbox';

function Stat({ label, value, tone }) {
    return (
        <div className="rounded-lg border border-gray-100 px-4 py-3 text-center">
            <p className={`text-2xl font-bold ${tone || 'text-gray-900'}`}>{value}</p>
            <p className="text-xs text-gray-500">{label}</p>
        </div>
    );
}

function Empty() {
    const { t } = useTranslation();
    return <p className="py-6 text-center text-sm text-gray-400">{t('tms.dashboard.noData')}</p>;
}

function TaskList({ rows, projectId }) {
    const { t } = useTranslation();
    if (!rows?.length) return <Empty />;
    return (
        <ul className="divide-y divide-gray-100 text-sm">
            {rows.map((r) => (
                <li key={r.id} className="flex items-center justify-between gap-3 py-2">
                    <Link className="truncate hover:underline" to={`/projects/${projectId}?tab=tasks&task=${r.key}`}><span className="font-mono text-xs text-gray-500">{r.key}</span> {r.title}</Link>
                    <span className="shrink-0 text-xs text-gray-500">{t('tms.dashboard.due', { date: r.due_date })}</span>
                </li>
            ))}
        </ul>
    );
}

function Widget({ type, data, projectId }) {
    const { t } = useTranslation();

    if (type === 'overview') {
        return (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Stat label={t('tms.dashboard.total')} value={data.total} />
                <Stat label={t('tms.dashboard.open')} value={data.open} />
                <Stat label={t('tms.dashboard.done')} value={data.done} tone="text-emerald-600" />
                <Stat label={t('tms.dashboard.overdue')} value={data.overdue} tone={data.overdue ? 'text-red-600' : undefined} />
            </div>
        );
    }

    if (type === 'status_breakdown' || type === 'priority_breakdown') {
        if (!data?.length) return <Empty />;
        return (
            <div style={{ height: 220 }}>
                <ResponsiveContainer>
                    <PieChart>
                        <Pie data={data} dataKey="count" nameKey="label" innerRadius={45} outerRadius={80} paddingAngle={2}>
                            {data.map((d) => <Cell key={d.label} fill={d.color} />)}
                        </Pie>
                        <Tooltip /><Legend />
                    </PieChart>
                </ResponsiveContainer>
            </div>
        );
    }

    if (type === 'assignee_load') {
        if (!data?.length) return <Empty />;
        return (
            <div style={{ height: Math.max(160, data.length * 32) }}>
                <ResponsiveContainer>
                    <BarChart data={data} layout="vertical" margin={{ left: 20 }}>
                        <CartesianGrid strokeDasharray="3 3" /><XAxis type="number" allowDecimals={false} tick={{ fontSize: 11 }} /><YAxis type="category" dataKey="label" width={110} tick={{ fontSize: 11 }} /><Tooltip />
                        <Bar dataKey="open" fill="#2563eb" name={t('tms.dashboard.open')} />
                    </BarChart>
                </ResponsiveContainer>
            </div>
        );
    }

    if (type === 'created_vs_done') {
        return (
            <div style={{ height: 220 }}>
                <ResponsiveContainer>
                    <LineChart data={data}>
                        <CartesianGrid strokeDasharray="3 3" /><XAxis dataKey="date" tick={{ fontSize: 10 }} /><YAxis allowDecimals={false} tick={{ fontSize: 11 }} /><Tooltip /><Legend />
                        <Line type="monotone" dataKey="created" stroke="#9ca3af" name={t('tms.dashboard.created')} dot={false} />
                        <Line type="monotone" dataKey="done" stroke="#10b981" name={t('tms.dashboard.completed')} dot={false} />
                    </LineChart>
                </ResponsiveContainer>
            </div>
        );
    }

    return <TaskList rows={data} projectId={projectId} />;
}

/** The project's dashboard: your arrangement, or the team default, of a fixed set of live widgets. */
export default function DashboardPanel({ projectId, canShare }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [state, setState] = useState(null);
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState([]);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get(`/projects/${projectId}/dashboard`).then(({ data }) => { setState(data); setDraft(data.widgets); }).catch(() => setError(t('tms.dashboard.loadFailed')));
    }, [projectId, t]);

    useEffect(load, [load]);

    async function save(shared) {
        try {
            await api.put(`/projects/${projectId}/dashboard`, { widgets: draft, shared });
            toast.success(t('tms.dashboard.saved'));
            setEditing(false);
            load();
        } catch {
            toast.error(t('tms.dashboard.saveFailed'));
        }
    }

    async function reset() {
        await api.delete(`/projects/${projectId}/dashboard`).catch(() => {});
        setEditing(false);
        load();
    }

    if (error) return <Alert>{error}</Alert>;
    if (!state) return <ContentSkeleton size="md" />;

    const toggle = (w) => setDraft((d) => (d.includes(w) ? d.filter((x) => x !== w) : [...d, w]));

    return (
        <div className="space-y-4">
            <div className="flex justify-end gap-2">
                <Button size="sm" variant="secondary" onClick={() => setEditing((v) => !v)}>{editing ? t('tms.dashboard.cancel') : t('tms.dashboard.customize')}</Button>
            </div>
            {editing && (
                <Card title={t('tms.dashboard.pick')}>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {state.available.map((w) => <Checkbox key={w} label={t(`tms.dashboard.widgets.${w}`)} checked={draft.includes(w)} onChange={() => toggle(w)} />)}
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button onClick={() => save(false)} disabled={draft.length === 0}>{t('tms.dashboard.saveMine')}</Button>
                        {canShare && <Button variant="secondary" onClick={() => save(true)} disabled={draft.length === 0}>{t('tms.dashboard.saveTeam')}</Button>}
                        <Button variant="ghost" onClick={reset}>{t('tms.dashboard.reset')}</Button>
                    </div>
                </Card>
            )}
            <div className="grid gap-4 lg:grid-cols-2">
                {state.widgets.map((w) => (
                    <div key={w} className={w === 'overview' ? 'lg:col-span-2' : ''}>
                        <Card title={t(`tms.dashboard.widgets.${w}`)}><Widget type={w} data={state.data[w]} projectId={projectId} /></Card>
                    </div>
                ))}
            </div>
        </div>
    );
}
