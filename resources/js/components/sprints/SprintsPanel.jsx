import { useCallback, useEffect, useState } from 'react';
import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import api, { fieldErrors } from '../../services/api';
import Card from '../ui/Card';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Spinner from '../ui/Spinner';
import { useToast } from '../../context/ToastContext';
import { formatDate } from '../../utils/format';

const sel = 'rounded-lg border border-gray-300 px-2 py-1.5 text-sm';

function TaskRow({ task, sprints, onMove, canPlan }) {
    return (
        <li className="flex flex-wrap items-center gap-2 border-t border-gray-100 px-3 py-2 text-sm">
            <span className="font-mono text-xs text-gray-400">{task.key}</span>
            <span className={`min-w-0 flex-1 truncate ${task.status?.is_done ? 'text-gray-400 line-through' : 'text-gray-800'}`}>{task.title}</span>
            <span className="text-xs text-gray-500">{task.story_points != null ? `${task.story_points} pts` : '— pts'}</span>
            <span className="w-24 truncate text-xs text-gray-500">{task.assignee?.name || 'Unassigned'}</span>
            {canPlan && (
                <select className={sel} value="" onChange={(e) => e.target.value && onMove(task, e.target.value)}>
                    <option value="">Move to…</option>
                    {task.sprint_id && <option value="backlog">Backlog</option>}
                    {sprints.filter((s) => s.status !== 'completed' && s.id !== task.sprint_id).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
            )}
        </li>
    );
}

/** Sprints & backlog planning, and the agile reports computed from the tracker's own history. */
export default function SprintsPanel({ projectId, canPlan }) {
    const toast = useToast();
    const [view, setView] = useState('plan');
    const [data, setData] = useState(null);
    const [sprintTasks, setSprintTasks] = useState({});
    const [form, setForm] = useState({ name: '', goal: '', start_date: '', end_date: '' });
    const [errors, setErrors] = useState({});
    const [completing, setCompleting] = useState(null);
    const [leftover, setLeftover] = useState({ mode: 'backlog', target: '' });
    const [reports, setReports] = useState(null);

    const load = useCallback(async () => {
        const { data: d } = await api.get(`/projects/${projectId}/sprints`);
        setData(d);
        const active = d.sprints.filter((s) => s.status !== 'completed');
        const entries = await Promise.all(active.map((s) => api.get(`/projects/${projectId}/sprints/${s.id}`).then(({ data: x }) => [s.id, x.tasks])));
        setSprintTasks(Object.fromEntries(entries));
    }, [projectId]);
    useEffect(() => { load(); }, [load]);
    useEffect(() => { if (view === 'reports' && !reports) api.get(`/projects/${projectId}/agile-reports`).then(({ data: r }) => setReports(r)); }, [view, reports, projectId]);

    async function act(fn, ok) {
        try { await fn(); if (ok) toast.success(ok); await load(); setReports(null); } catch (e) { toast.error(fieldErrors(e).form || fieldErrors(e).target_sprint_id || e.response?.data?.message || 'Action failed.'); }
    }

    async function create(e) {
        e.preventDefault(); setErrors({});
        try {
            await api.post(`/projects/${projectId}/sprints`, { ...form, start_date: form.start_date || null, end_date: form.end_date || null });
            setForm({ name: '', goal: '', start_date: '', end_date: '' }); toast.success('Sprint created.'); load();
        } catch (err) { setErrors(fieldErrors(err)); }
    }

    function move(task, to) {
        act(async () => {
            if (to === 'backlog') await api.delete(`/projects/${projectId}/sprints/${task.sprint_id}/tasks/${task.id}`);
            else await api.post(`/projects/${projectId}/sprints/${to}/tasks`, { task_ids: [task.id] });
        });
    }

    if (!data) return <div className="flex justify-center py-10"><Spinner /></div>;
    const open = data.sprints.filter((s) => s.status !== 'completed');
    const closed = data.sprints.filter((s) => s.status === 'completed');

    return (
        <div className="space-y-5">
            <div className="flex gap-2 text-sm">
                {[['plan', 'Planning'], ['reports', 'Reports']].map(([k, l]) => (
                    <button key={k} onClick={() => setView(k)} className={`rounded-full px-3 py-1 ${view === k ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600'}`}>{l}</button>
                ))}
            </div>

            {view === 'plan' && (
                <>
                    {canPlan && (
                        <Card title="New sprint">
                            <form onSubmit={create} className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                                <Input label="Name" name="name" required value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={errors.name} />
                                <Input label="Start" type="date" name="start_date" value={form.start_date} onChange={(e) => setForm((f) => ({ ...f, start_date: e.target.value }))} error={errors.start_date} />
                                <Input label="End" type="date" name="end_date" value={form.end_date} onChange={(e) => setForm((f) => ({ ...f, end_date: e.target.value }))} error={errors.end_date} />
                                <div className="flex items-end"><Button type="submit">Create sprint</Button></div>
                                <div className="sm:col-span-4"><Input label="Goal (optional)" name="goal" value={form.goal} onChange={(e) => setForm((f) => ({ ...f, goal: e.target.value }))} /></div>
                            </form>
                        </Card>
                    )}

                    {open.map((s) => (
                        <div key={s.id} className="rounded-xl border border-gray-200 bg-white">
                            <div className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold text-gray-900">{s.name} <Badge>{s.status}</Badge></p>
                                    <p className="text-xs text-gray-500">{s.start_date ? `${formatDate(s.start_date)} → ${formatDate(s.end_date)}` : 'No dates yet'} · {s.tasks_count} tasks · {s.points || 0} pts{s.goal ? ` · ${s.goal}` : ''}</p>
                                </div>
                                {canPlan && s.status === 'planned' && <Button size="sm" onClick={() => act(() => api.post(`/projects/${projectId}/sprints/${s.id}/start`), 'Sprint started.')}>Start sprint</Button>}
                                {canPlan && s.status === 'planned' && <Button size="sm" variant="danger" onClick={() => window.confirm('Delete this sprint? Its tasks return to the backlog.') && act(() => api.delete(`/projects/${projectId}/sprints/${s.id}`), 'Sprint deleted.')}>Delete</Button>}
                                {canPlan && s.status === 'active' && <Button size="sm" variant="secondary" onClick={() => { setCompleting(s); setLeftover({ mode: 'backlog', target: '' }); }}>Complete sprint</Button>}
                            </div>
                            {completing?.id === s.id && (
                                <div className="border-t border-gray-100 bg-gray-50 px-4 py-3 text-sm">
                                    <p className="mb-2 font-medium">Unfinished work goes to:</p>
                                    <label className="mr-4"><input type="radio" checked={leftover.mode === 'backlog'} onChange={() => setLeftover({ mode: 'backlog', target: '' })} /> the backlog</label>
                                    <label className="mr-2"><input type="radio" checked={leftover.mode === 'sprint'} onChange={() => setLeftover({ mode: 'sprint', target: '' })} /> a planned sprint</label>
                                    {leftover.mode === 'sprint' && (
                                        <select className={sel} value={leftover.target} onChange={(e) => setLeftover((l) => ({ ...l, target: e.target.value }))}>
                                            <option value="">Choose…</option>
                                            {data.sprints.filter((x) => x.status === 'planned').map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                                        </select>
                                    )}
                                    <div className="mt-3 flex gap-2">
                                        <Button size="sm" variant="secondary" onClick={() => setCompleting(null)}>Cancel</Button>
                                        <Button size="sm" onClick={() => act(() => api.post(`/projects/${projectId}/sprints/${s.id}/complete`, { leftover: leftover.mode, target_sprint_id: leftover.target || null }), 'Sprint completed.').then(() => setCompleting(null))}>Complete</Button>
                                    </div>
                                </div>
                            )}
                            <ul>{(sprintTasks[s.id] || []).map((t) => <TaskRow key={t.id} task={t} sprints={data.sprints} onMove={move} canPlan={canPlan} />)}</ul>
                            {(sprintTasks[s.id] || []).length === 0 && <p className="border-t border-gray-100 px-4 py-3 text-sm text-gray-400">Nothing planned. Move work in from the backlog below.</p>}
                        </div>
                    ))}

                    <div className="rounded-xl border border-gray-200 bg-white">
                        <p className="px-4 py-3 font-semibold text-gray-900">Backlog <span className="text-sm font-normal text-gray-400">({data.backlog.length})</span></p>
                        <ul>{data.backlog.map((t) => <TaskRow key={t.id} task={t} sprints={data.sprints} onMove={move} canPlan={canPlan} />)}</ul>
                        {data.backlog.length === 0 && <p className="border-t border-gray-100 px-4 py-3 text-sm text-gray-400">The backlog is empty.</p>}
                    </div>

                    {closed.length > 0 && (
                        <Card title="Completed sprints">
                            <ul className="divide-y divide-gray-100 text-sm">
                                {closed.map((s) => <li key={s.id} className="flex flex-wrap justify-between gap-2 py-2"><span className="font-medium">{s.name}</span><span className="text-gray-500">{s.completed_points ?? 0} of {s.committed_points ?? 0} pts · {formatDate(s.completed_at)}</span></li>)}
                            </ul>
                        </Card>
                    )}
                </>
            )}

            {view === 'reports' && (!reports ? <div className="flex justify-center py-10"><Spinner /></div> : (
                <div className="space-y-5">
                    <Card title={reports.sprint ? `Burndown — ${reports.sprint.name}` : 'Burndown'} subtitle="Remaining story points at the end of each day, against the ideal line.">
                        {reports.burndown?.days?.length ? (
                            <div style={{ height: 260 }}>
                                <ResponsiveContainer>
                                    <LineChart data={reports.burndown.days}>
                                        <CartesianGrid strokeDasharray="3 3" /><XAxis dataKey="date" tick={{ fontSize: 11 }} /><YAxis tick={{ fontSize: 11 }} /><Tooltip /><Legend />
                                        <Line type="monotone" dataKey="remaining" stroke="#4f46e5" name="Remaining" dot={false} />
                                        <Line type="monotone" dataKey="ideal" stroke="#9ca3af" strokeDasharray="5 5" name="Ideal" dot={false} />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        ) : <p className="text-sm text-gray-400">Start a sprint to see its burndown.</p>}
                    </Card>
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                        <Card title="Velocity" subtitle="Committed vs completed points, last sprints.">
                            {reports.velocity.length ? (
                                <div style={{ height: 220 }}><ResponsiveContainer><BarChart data={reports.velocity}><CartesianGrid strokeDasharray="3 3" /><XAxis dataKey="sprint" tick={{ fontSize: 11 }} /><YAxis tick={{ fontSize: 11 }} /><Tooltip /><Legend /><Bar dataKey="committed" fill="#c7d2fe" name="Committed" /><Bar dataKey="completed" fill="#4f46e5" name="Completed" /></BarChart></ResponsiveContainer></div>
                            ) : <p className="text-sm text-gray-400">Complete a sprint to see velocity.</p>}
                        </Card>
                        <Card title="Throughput" subtitle="Tasks completed per week.">
                            <div style={{ height: 220 }}><ResponsiveContainer><BarChart data={reports.throughput}><CartesianGrid strokeDasharray="3 3" /><XAxis dataKey="week" tick={{ fontSize: 11 }} /><YAxis allowDecimals={false} tick={{ fontSize: 11 }} /><Tooltip /><Bar dataKey="completed" fill="#10b981" name="Completed" /></BarChart></ResponsiveContainer></div>
                        </Card>
                    </div>
                    <Card title="Flow" subtitle={`Tasks completed in the last ${reports.flow.window_days} days: ${reports.flow.completed}`}>
                        <div className="grid grid-cols-2 gap-4 text-center text-sm sm:grid-cols-4">
                            {[['Lead time (avg)', reports.flow.lead_time.avg], ['Lead time (median)', reports.flow.lead_time.median], ['Cycle time (avg)', reports.flow.cycle_time.avg], ['Cycle time (median)', reports.flow.cycle_time.median]].map(([l, v]) => (
                                <div key={l} className="rounded-lg bg-gray-50 p-3"><p className="text-xl font-bold text-gray-900">{v ?? '—'}{v != null ? 'd' : ''}</p><p className="text-xs text-gray-500">{l}</p></div>
                            ))}
                        </div>
                        <p className="mt-2 text-xs text-gray-400">Lead time = created → completed. Cycle time = first moved to an in-progress status → completed.</p>
                    </Card>
                </div>
            ))}
        </div>
    );
}
