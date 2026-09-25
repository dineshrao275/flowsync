import { useEffect, useState } from 'react';
import { fieldErrors } from '../../services/api';
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
    const { hasModule } = useAuth();
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [tab, setTab] = useState('details');

    useEffect(() => {
        setForm(
            task
                ? {
                      title: task.title,
                      description: task.description || '',
                      status_id: task.status_id,
                      priority_id: task.priority_id ?? '',
                      assignee_id: task.assignee_id ?? '',
                      parent_id: task.parent_id ?? '',
                      labels: task.labels?.map((l) => l.id) || [],
                      due_date: task.due_date || '',
                      estimate_minutes: task.estimate_minutes ?? '',
                  }
                : null,
        );
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

    function submit(e) {
        e.preventDefault();
        setErrors({});
        onUpdate({
            ...form,
            assignee_id: form.assignee_id || null,
            parent_id: form.parent_id || null,
            due_date: form.due_date || null,
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
                                <div className="sm:col-span-2">
                                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Description</label>
                                    <textarea
                                        rows="5"
                                        className={`${fieldClass} min-h-32`}
                                        value={form.description}
                                        onChange={(e) => set('description', e.target.value)}
                                        disabled={!canEdit}
                                    />
                                </div>

                                <aside className="space-y-4">
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
                                    <Input
                                        label="Due date"
                                        type="date"
                                        value={form.due_date}
                                        onChange={(e) => set('due_date', e.target.value)}
                                        disabled={!canEdit}
                                    />
                                    <Input
                                        label="Estimate (minutes)"
                                        type="number"
                                        min="0"
                                        value={form.estimate_minutes}
                                        onChange={(e) => set('estimate_minutes', e.target.value)}
                                        disabled={!canEdit}
                                    />
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
                        </div>
                    )}
                </>
            )}
        </Drawer>
    );
}