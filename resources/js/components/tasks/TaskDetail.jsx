import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Select from '../ui/Select';
import Drawer from '../ui/Drawer';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import CommentThread from './CommentThread';
import AttachmentList from './AttachmentList';
import DependencyPanel from './DependencyPanel';
import ActivityFeed from './ActivityFeed';
import WorkLogPanel from './WorkLogPanel';
import TaskLinkPanel from '../hrms/TaskLinkPanel';
import { useAuth } from '../../context/AuthContext';
import { fieldClass } from '../ui/fieldStyles';

export default function TaskDetail({
    task,
    projectId,
    projectKey,
    options,
    topLevelTasks,
    initialSection,
    canEdit,
    canAssign,
    canDelete,
    canMove,
    isCollabManager,
    canLog,
    canManageLogs,
    saving,
    error,
    onClose,
    onUpdate,
    onDelete,
}) {
    const { user, hasModule } = useAuth();
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [tab, setTab] = useState('details');
    const [watchers, setWatchers] = useState([]);
    const [togglingWatch, setTogglingWatch] = useState(false);
    const [watcherError, setWatcherError] = useState(null);

    useEffect(() => {
        setForm(
            task
                ? {
                      title: task.title,
                      description: task.description || '',
                      issue_type_id: task.issue_type_id ?? task.issue_type?.id ?? '',
                      version_id: task.version_id ?? task.version?.id ?? '',
                      epic_id: task.epic_id ?? '',
                      components: task.components?.map((c) => c.id) || [],
                      status_id: task.status_id,
                      priority_id: task.priority_id ?? '',
                      assignee_id: task.assignee_id ?? '',
                      parent_id: task.parent_id ?? '',
                      labels: task.labels?.map((l) => l.id) || [],
                      start_date: task.start_date || '',
                      due_date: task.due_date || '',
                      story_points: task.story_points ?? '',
                      estimate_minutes: task.estimate_minutes ?? '',
                  }
                : null,
        );
        setWatchers(task?.watchers || []);
        setWatcherError(null);
        setErrors({});
        setTab(['details', 'comments', 'attachments', 'dependencies', 'time', 'activity'].includes(initialSection)
            ? initialSection
            : 'details');
    }, [task, initialSection]);

    if (!task) return null;

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function toggleLabel(id) {
        setForm((f) => ({
            ...f,
            labels: f.labels.includes(id) ? f.labels.filter((l) => l !== id) : [...f.labels, id],
        }));
    }

    function toggleComponent(id) {
        setForm((f) => ({
            ...f,
            components: f.components.includes(id) ? f.components.filter((c) => c !== id) : [...f.components, id],
        }));
    }

    const isWatching = watchers.some((w) => w.id === user?.id);

    async function toggleWatch() {
        setTogglingWatch(true);
        setWatcherError(null);
        try {
            if (isWatching) {
                await api.delete(`/projects/${projectId}/tasks/${task.id}/watchers`);
                setWatchers((prev) => prev.filter((w) => w.id !== user?.id));
            } else {
                const res = await api.post(`/projects/${projectId}/tasks/${task.id}/watchers`);
                if (res.data?.watchers) {
                    setWatchers(res.data.watchers);
                } else {
                    setWatchers((prev) => [...prev, { id: user.id, name: user.name, email: user.email }]);
                }
            }
        } catch (err) {
            setWatcherError(err?.response?.data?.message || 'Failed to update watch status.');
        } finally {
            setTogglingWatch(false);
        }
    }

    async function addWatcherUser(userId) {
        if (!userId) return;
        setWatcherError(null);
        try {
            const res = await api.post(`/projects/${projectId}/tasks/${task.id}/watchers`, { user_id: Number(userId) });
            if (res.data?.watchers) {
                setWatchers(res.data.watchers);
            }
        } catch (err) {
            setWatcherError(err?.response?.data?.message || 'Failed to add watcher.');
        }
    }

    async function removeWatcherUser(userId) {
        setWatcherError(null);
        try {
            await api.delete(`/projects/${projectId}/tasks/${task.id}/watchers/${userId}`);
            setWatchers((prev) => prev.filter((w) => w.id !== userId));
        } catch (err) {
            setWatcherError(err?.response?.data?.message || 'Failed to remove watcher.');
        }
    }

    function submit(e) {
        e.preventDefault();
        setErrors({});
        onUpdate({
            ...form,
            issue_type_id: form.issue_type_id || null,
            version_id: form.version_id || null,
            epic_id: form.epic_id || null,
            components: form.components,
            assignee_id: form.assignee_id || null,
            parent_id: form.parent_id || null,
            start_date: form.start_date || null,
            due_date: form.due_date || null,
            story_points: form.story_points === '' ? null : Number(form.story_points),
            estimate_minutes: form.estimate_minutes === '' ? null : Number(form.estimate_minutes),
            labels: form.labels,
        }).catch((err) => setErrors(fieldErrors(err)));
    }

    const disabled = !canEdit || (task.assignee_id !== (form?.assignee_id || null) && !canAssign);

    const tabs = [
        { key: 'details', label: 'Details' },
        { key: 'comments', label: 'Comments' },
        { key: 'attachments', label: 'Attachments' },
        { key: 'dependencies', label: 'Dependencies' },
        ...(hasModule('time_tracking') ? [{ key: 'time', label: 'Time' }] : []),
        // HR bridges ride the core module like every HRMS surface; the
        // panel itself answers 403 through the project policy for logins
        // that may work the task but not read its HR metadata.
        ...(hasModule('hrms.core') ? [{ key: 'links', label: 'Links' }] : []),
        { key: 'activity', label: 'Activity' },
    ];

    return (
        <Drawer
            open={!!task}
            onClose={onClose}
            title={form ? task.title : 'Task'}
            subtitle={form ? `${projectKey} · ${task.key}` : ''}
            footer={
                form && tab === 'details' ? (
                    <div className="flex justify-between gap-2">
                        {canDelete ? (
                            <Button type="button" variant="danger" onClick={onDelete}>
                                Delete
                            </Button>
                        ) : (
                            <span />
                        )}
                        {canEdit && (
                            <Button type="submit" form="task-detail-form" loading={saving} disabled={disabled}>
                                Save changes
                            </Button>
                        )}
                    </div>
                ) : null
            }
        >
            {!form ? (
                <div className="flex justify-center py-20">
                    <Spinner />
                </div>
            ) : (
                <>
                    <div className="flex gap-1 border-b border-gray-200 px-6 pt-2">
                        {tabs.map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => setTab(t.key)}
                                className={`-mb-px border-b-2 px-2 py-2 text-xs font-medium transition ${
                                    tab === t.key
                                        ? 'border-indigo-600 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>

                    {tab === 'details' ? (
                        <form id="task-detail-form" onSubmit={submit} className="space-y-4 px-6 py-5">
                            {error && <Alert>{error}</Alert>}
                            {errors.form && <Alert>{errors.form}</Alert>}

                            <Input
                                label="Title"
                                name="task-title"
                                value={form.title}
                                onChange={(e) => set('title', e.target.value)}
                                error={errors.title}
                                disabled={!canEdit}
                                required
                            />

                            <div className="grid gap-5 sm:grid-cols-3">
                                <div className="space-y-4 sm:col-span-2">
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Description</label>
                                        <textarea
                                            rows="5"
                                            className={`${fieldClass} min-h-32`}
                                            value={form.description}
                                            placeholder="Context, acceptance criteria…"
                                            onChange={(e) => set('description', e.target.value)}
                                            disabled={!canEdit}
                                        />
                                    </div>

                                    {options.components?.length > 0 && (
                                        <div>
                                            <label className="mb-1.5 block text-sm font-medium text-gray-700">Components</label>
                                            <div className="flex flex-wrap gap-2">
                                                {options.components.map((comp) => (
                                                    <button
                                                        key={comp.id}
                                                        type="button"
                                                        onClick={() => canEdit && toggleComponent(comp.id)}
                                                        className={`rounded-md px-2.5 py-1 text-xs font-medium transition disabled:cursor-not-allowed ${
                                                            form.components?.includes(comp.id)
                                                                ? 'bg-indigo-600 text-white'
                                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                                        }`}
                                                        disabled={!canEdit}
                                                    >
                                                        {comp.name}
                                                    </button>
                                                ))}
                                            </div>
                                            {errors.components && <p className="mt-1.5 text-sm text-red-600">{errors.components}</p>}
                                        </div>
                                    )}

                                    <div className="rounded-lg border border-gray-100 bg-gray-50/50 p-3.5 space-y-3">
                                        <h4 className="text-xs font-semibold uppercase tracking-wider text-gray-500">Dates & Effort</h4>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <Input
                                                label="Start date"
                                                type="date"
                                                value={form.start_date}
                                                onChange={(e) => set('start_date', e.target.value)}
                                                disabled={!canEdit}
                                                error={errors.start_date}
                                            />
                                            <Input
                                                label="Due date"
                                                type="date"
                                                value={form.due_date}
                                                onChange={(e) => set('due_date', e.target.value)}
                                                disabled={!canEdit}
                                                error={errors.due_date}
                                            />
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <Input
                                                label="Story points"
                                                type="number"
                                                step="any"
                                                min="0"
                                                placeholder="e.g. 3 or 5.5"
                                                value={form.story_points}
                                                onChange={(e) => set('story_points', e.target.value)}
                                                disabled={!canEdit}
                                                error={errors.story_points}
                                            />
                                            <Input
                                                label="Estimate (minutes)"
                                                type="number"
                                                min="0"
                                                value={form.estimate_minutes}
                                                onChange={(e) => set('estimate_minutes', e.target.value)}
                                                disabled={!canEdit}
                                                error={errors.estimate_minutes}
                                            />
                                        </div>
                                    </div>
                                </div>

                                <aside className="space-y-4">
                                    {options.issue_types?.length > 0 && (
                                        <Select
                                            label="Issue Type"
                                            value={form.issue_type_id}
                                            onChange={(e) => set('issue_type_id', e.target.value)}
                                            disabled={!canEdit}
                                            error={errors.issue_type_id}
                                        >
                                            {options.issue_types.map((t) => (
                                                <option key={t.id} value={t.id}>
                                                    {t.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}

                                    {options.versions?.length > 0 && (
                                        <Select
                                            label="Version"
                                            value={form.version_id}
                                            onChange={(e) => set('version_id', e.target.value)}
                                            disabled={!canEdit}
                                            error={errors.version_id}
                                        >
                                            <option value="">No version</option>
                                            {options.versions.map((v) => (
                                                <option key={v.id} value={v.id}>
                                                    {v.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}

                                    {options.epics?.length > 0 && (
                                        <Select
                                            label="Epic"
                                            value={form.epic_id}
                                            onChange={(e) => set('epic_id', e.target.value)}
                                            disabled={!canEdit}
                                            error={errors.epic_id}
                                        >
                                            <option value="">No epic</option>
                                            {options.epics.filter((e) => e.id !== task.id).map((e) => (
                                                <option key={e.id} value={e.id}>{e.key} · {e.title}</option>
                                            ))}
                                        </Select>
                                    )}

                                    <Select
                                        label="Status"
                                        value={form.status_id}
                                        onChange={(e) => set('status_id', e.target.value)}
                                        disabled={!canMove}
                                        error={errors.status_id}
                                    >
                                        {options.statuses.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </Select>
                                    <Select
                                        label="Priority"
                                        value={form.priority_id}
                                        onChange={(e) => set('priority_id', e.target.value)}
                                        disabled={!canEdit}
                                    >
                                        <option value="">None</option>
                                        {options.priorities.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </Select>
                                    <Select
                                        label="Assignee"
                                        value={form.assignee_id}
                                        onChange={(e) => set('assignee_id', e.target.value)}
                                        disabled={!canAssign}
                                        error={errors.assignee_id}
                                    >
                                        <option value="">Unassigned</option>
                                        {options.assignees.map((u) => (
                                            <option key={u.id} value={u.id}>
                                                {u.name}
                                            </option>
                                        ))}
                                    </Select>
                                    <Select
                                        label="Parent task"
                                        value={form.parent_id}
                                        onChange={(e) => set('parent_id', e.target.value)}
                                        disabled={!canEdit}
                                        error={errors.parent_id}
                                    >
                                        <option value="">None</option>
                                        {topLevelTasks
                                            .filter((t) => t.id !== task.id)
                                            .map((t) => (
                                                <option key={t.id} value={t.id}>
                                                    {t.key} · {t.title}
                                                </option>
                                            ))}
                                    </Select>
                                    {options.labels?.length > 0 && (
                                        <div>
                                            <label className="mb-1.5 block text-sm font-medium text-gray-700">Labels</label>
                                            <div className="flex flex-wrap gap-2">
                                                {options.labels.map((label) => (
                                                    <button
                                                        key={label.id}
                                                        type="button"
                                                        onClick={() => canEdit && toggleLabel(label.id)}
                                                        className={`rounded-full px-3 py-1 text-xs font-medium transition disabled:cursor-not-allowed ${
                                                            form.labels.includes(label.id)
                                                                ? 'bg-[var(--accent)] text-[var(--accent-contrast)]'
                                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                                        }`}
                                                        disabled={!canEdit}
                                                    >
                                                        {label.name}
                                                    </button>
                                                ))}
                                            </div>
                                            {errors.labels && <p className="mt-1.5 text-sm text-red-600">{errors.labels}</p>}
                                        </div>
                                    )}

                                    <div className="border-t border-gray-100 pt-3">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                Watchers ({watchers.length})
                                            </span>
                                            <button
                                                type="button"
                                                onClick={toggleWatch}
                                                disabled={togglingWatch}
                                                className={`rounded px-2 py-0.5 text-xs font-semibold transition ${
                                                    isWatching
                                                        ? 'bg-indigo-100 text-indigo-700 hover:bg-indigo-200'
                                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                                }`}
                                            >
                                                {isWatching ? 'Watching' : 'Watch'}
                                            </button>
                                        </div>
                                        {watcherError && <p className="mt-1 text-xs text-red-600">{watcherError}</p>}
                                        {watchers.length > 0 && (
                                            <div className="mt-2 flex flex-wrap gap-1.5">
                                                {watchers.map((w) => (
                                                    <span
                                                        key={w.id}
                                                        className="inline-flex items-center gap-1 rounded-full bg-gray-100 py-0.5 pl-2 pr-1 text-xs text-gray-700"
                                                    >
                                                        <span>{w.name}</span>
                                                        {(canEdit || w.id === user?.id) && (
                                                            <button
                                                                type="button"
                                                                onClick={() => removeWatcherUser(w.id)}
                                                                className="rounded-full p-0.5 text-gray-400 hover:bg-gray-200 hover:text-gray-600"
                                                                title="Remove watcher"
                                                            >
                                                                ×
                                                            </button>
                                                        )}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                        {canEdit && options.assignees?.some((u) => !watchers.some((w) => w.id === u.id)) && (
                                            <select
                                                className="mt-2 block w-full rounded-md border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600 shadow-sm focus:border-indigo-500 focus:outline-none"
                                                value=""
                                                onChange={(e) => addWatcherUser(e.target.value)}
                                            >
                                                <option value="">+ Add watcher…</option>
                                                {options.assignees
                                                    .filter((u) => !watchers.some((w) => w.id === u.id))
                                                    .map((u) => (
                                                        <option key={u.id} value={u.id}>
                                                            {u.name}
                                                        </option>
                                                    ))}
                                            </select>
                                        )}
                                    </div>

                                    <dl className="space-y-2 border-t border-gray-100 pt-3 text-sm">
                                        <div className="flex items-center justify-between gap-2">
                                            <dt className="text-xs uppercase tracking-wide text-gray-400">Reporter</dt>
                                            <dd className="font-medium text-gray-700">{task.reporter?.name ?? '—'}</dd>
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <dt className="text-xs uppercase tracking-wide text-gray-400">Created</dt>
                                            <dd className="font-medium text-gray-700">
                                                {task.created_at ? new Date(task.created_at).toLocaleDateString() : '—'}
                                            </dd>
                                        </div>
                                    </dl>
                                </aside>
                            </div>
                        </form>
                    ) : (
                        <div className="px-6 py-5">
                            {tab === 'comments' && (
                                <CommentThread task={task} projectId={projectId} isManager={isCollabManager} />
                            )}
                            {tab === 'attachments' && (
                                <AttachmentList
                                    task={task}
                                    projectId={projectId}
                                    canUpload={isCollabManager}
                                    canManage={isCollabManager}
                                />
                            )}
                            {tab === 'dependencies' && (
                                <DependencyPanel
                                    task={task}
                                    projectId={projectId}
                                    canManage={isCollabManager}
                                    allTasks={topLevelTasks}
                                />
                            )}
                            {tab === 'time' && hasModule('time_tracking') && (
                                <WorkLogPanel
                                    task={task}
                                    projectId={projectId}
                                    canLog={canLog}
                                    canManage={canManageLogs}
                                />
                            )}
                            {tab === 'activity' && <ActivityFeed task={task} projectId={projectId} />}
                            {tab === 'links' && hasModule('hrms.core') && (
                                <TaskLinkPanel task={task} canManage={canEdit} />
                            )}
                        </div>
                    )}
                </>
            )}
        </Drawer>
    );
}