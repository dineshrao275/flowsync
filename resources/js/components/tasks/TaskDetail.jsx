import { useEffect, useRef, useState } from 'react';
import UserChip from '../ui/UserChip';
import PersonSelect from '../ui/PersonSelect';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Select from '../ui/Select';
import Drawer from '../ui/Drawer';
import Alert from '../ui/Alert';
import ContentSkeleton from '../ui/ContentSkeleton';
import CommentThread from './CommentThread';
import AttachmentList from './AttachmentList';
import DependencyPanel from './DependencyPanel';
import ActivityFeed from './ActivityFeed';
import WorkLogPanel from './WorkLogPanel';
import TaskLinkPanel from '../hrms/TaskLinkPanel';
import { useAuth } from '../../context/AuthContext';
import RichTextEditor from '../ui/RichTextEditor';
import RichText from '../ui/RichText';
import Timestamp from '../ui/Timestamp';
import { formatRelativeTime } from '../../utils/format';
import useUnsavedChanges from '../../hooks/useUnsavedChanges';
import CustomFieldInputs, { useCustomFieldDefinitions } from '../customFields/CustomFieldInputs';

export default function TaskDetail({
    task,
    projectId,
    projectKey,
    options,
    topLevelTasks,
    initialSection,
    initialCommentId = null,
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
    const { t } = useTranslation();
    const cfDefs = useCustomFieldDefinitions('task');
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [tab, setTab] = useState('details');
    const [watchers, setWatchers] = useState([]);
    const [togglingWatch, setTogglingWatch] = useState(false);
    const [watcherError, setWatcherError] = useState(null);
    // Snapshot of the form as loaded from the server; the drawer is "dirty"
    // while the live form differs from it.
    const baseline = useRef('');

    useEffect(() => {
        const loaded = task
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
                      custom_fields: { ...(task.custom_fields || {}) },
              }
            : null;
        baseline.current = JSON.stringify(loaded);
        setForm(loaded);
        setWatchers(task?.watchers || []);
        setWatcherError(null);
        setErrors({});
        setTab(['details', 'comments', 'attachments', 'dependencies', 'time', 'activity'].includes(initialSection)
            ? initialSection
            : 'details');
    }, [task, initialSection]);

    const dirty = form !== null && JSON.stringify(form) !== baseline.current;
    const confirmDiscard = useUnsavedChanges(dirty);

    async function handleClose() {
        if (await confirmDiscard()) onClose();
    }

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
            setWatcherError(err?.response?.data?.message || t('tms.detail.watchFailed'));
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
            setWatcherError(err?.response?.data?.message || t('tms.detail.watchAddFailed'));
        }
    }

    async function removeWatcherUser(userId) {
        setWatcherError(null);
        try {
            await api.delete(`/projects/${projectId}/tasks/${task.id}/watchers/${userId}`);
            setWatchers((prev) => prev.filter((w) => w.id !== userId));
        } catch (err) {
            setWatcherError(err?.response?.data?.message || t('tms.detail.watchRemoveFailed'));
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
        { key: 'details', label: t('tms.detail.tabs.details') },
        { key: 'comments', label: t('tms.detail.tabs.comments') },
        { key: 'attachments', label: t('tms.detail.tabs.attachments') },
        { key: 'dependencies', label: t('tms.detail.tabs.dependencies') },
        ...(hasModule('time_tracking') ? [{ key: 'time', label: t('tms.detail.tabs.time') }] : []),
        // HR bridges ride the core module like every HRMS surface; the
        // panel itself answers 403 through the project policy for logins
        // that may work the task but not read its HR metadata.
        ...(hasModule('hrms.core') ? [{ key: 'links', label: t('tms.detail.tabs.links') }] : []),
        { key: 'activity', label: t('tms.detail.tabs.activity') },
    ];

    return (
        <Drawer
            open={!!task}
            onClose={handleClose}
            allowFullscreen
            title={form ? task.title : t('tms.detail.fallbackTitle')}
            subtitle={form ? `${projectKey} · ${task.key}` : ''}
            footer={
                form && tab === 'details' ? (
                    <div className="flex justify-between gap-2">
                        {canDelete ? (
                            <Button type="button" variant="danger" onClick={onDelete}>
                                {t('tms.detail.delete')}
                            </Button>
                        ) : (
                            <span />
                        )}
                        {dirty && (
                            <span className="self-center text-caption font-medium text-warning" role="status">
                                {t('admin.common.unsavedBadge')}
                            </span>
                        )}
                        {canEdit && (
                            <Button type="submit" form="task-detail-form" loading={saving} disabled={disabled}>
                                {t('tms.detail.save')}
                            </Button>
                        )}
                    </div>
                ) : null
            }
        >
            {!form ? (
                <ContentSkeleton size="lg" />
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
                                        ? 'border-[var(--accent)] text-[var(--accent-soft-text)]'
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
                                label={t('tms.detail.fieldTitle')}
                                name="task-title"
                                value={form.title}
                                onChange={(e) => set('title', e.target.value)}
                                error={errors.title}
                                disabled={!canEdit}
                                required
                            />

                            <div className="grid gap-6 md:grid-cols-3 min-w-0">
                                <div className="space-y-4 md:col-span-2 min-w-0">
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.detail.description')}</label>
                                        {canEdit ? (
                                            <RichTextEditor
                                                value={form.description}
                                                onChange={(html) => set('description', html)}
                                                placeholder={t('tms.create.descriptionPlaceholder')}
                                                error={errors.description}
                                            />
                                        ) : form.description ? (
                                            <RichText value={form.description} />
                                        ) : (
                                            <p className="text-sm text-gray-400">{t('tms.detail.noDescription')}</p>
                                        )}
                                    </div>

                                    {options.components?.length > 0 && (
                                        <div>
                                            <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.detail.components')}</label>
                                            <div className="flex flex-wrap gap-2">
                                                {options.components.map((comp) => (
                                                    <button
                                                        key={comp.id}
                                                        type="button"
                                                        onClick={() => canEdit && toggleComponent(comp.id)}
                                                        className={`rounded-md px-2.5 py-1 text-xs font-medium transition disabled:cursor-not-allowed ${
                                                            form.components?.includes(comp.id)
                                                                ? 'bg-[var(--accent)] text-[var(--accent-contrast)]'
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

                                    <div className="rounded-lg border border-gray-100 bg-gray-50/50 p-3.5 space-y-3 min-w-0">
                                        <h4 className="text-xs font-semibold uppercase tracking-wider text-gray-500">{t('tms.detail.datesEffort')}</h4>
                                        <div className="grid gap-3 sm:grid-cols-2 min-w-0">
                                            <Input
                                                label={t('tms.detail.startDate')}
                                                type="date"
                                                value={form.start_date}
                                                onChange={(e) => set('start_date', e.target.value)}
                                                disabled={!canEdit}
                                                error={errors.start_date}
                                            />
                                            <Input
                                                label={t('tms.detail.dueDate')}
                                                type="date"
                                                value={form.due_date}
                                                onChange={(e) => set('due_date', e.target.value)}
                                                disabled={!canEdit}
                                                error={errors.due_date}
                                            />
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2 min-w-0">
                                            <Input
                                                label={t('tms.detail.storyPoints')}
                                                type="number"
                                                step="any"
                                                min="0"
                                                placeholder={t('tms.detail.storyPointsPlaceholder')}
                                                value={form.story_points}
                                                onChange={(e) => set('story_points', e.target.value)}
                                                disabled={!canEdit}
                                                error={errors.story_points}
                                            />
                                            <Input
                                                label={t('tms.detail.estimate')}
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

                                <aside className="space-y-4 min-w-0">
                                    {options.issue_types?.length > 0 && (
                                        <Select
                                            label={t('tms.detail.issueType')}
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
                                            label={t('tms.detail.version')}
                                            value={form.version_id}
                                            onChange={(e) => set('version_id', e.target.value)}
                                            disabled={!canEdit}
                                            error={errors.version_id}
                                        >
                                            <option value="">{t('tms.detail.noVersion')}</option>
                                            {options.versions.map((v) => (
                                                <option key={v.id} value={v.id}>
                                                    {v.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}

                                    {options.epics?.length > 0 && (
                                        <Select
                                            label={t('tms.detail.epic')}
                                            value={form.epic_id}
                                            onChange={(e) => set('epic_id', e.target.value)}
                                            disabled={!canEdit}
                                            error={errors.epic_id}
                                        >
                                            <option value="">{t('tms.detail.noEpic')}</option>
                                            {options.epics.filter((e) => e.id !== task.id).map((e) => (
                                                <option key={e.id} value={e.id}>{e.key} · {e.title}</option>
                                            ))}
                                        </Select>
                                    )}

                                    <Select
                                        label={t('tms.detail.status')}
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
                                        label={t('tms.detail.priority')}
                                        value={form.priority_id}
                                        onChange={(e) => set('priority_id', e.target.value)}
                                        disabled={!canEdit}
                                    >
                                        <option value="">{t('tms.detail.none')}</option>
                                        {options.priorities.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </Select>
                                    <PersonSelect
                                        label={t('tms.detail.assignee')}
                                        value={form.assignee_id}
                                        onChange={(e) => set('assignee_id', e.target.value)}
                                        disabled={!canAssign}
                                        error={errors.assignee_id}
                                     placeholder={t('tms.detail.unassigned')} people={options.assignees} />
                                    <Select
                                        label={t('tms.detail.parent')}
                                        value={form.parent_id}
                                        onChange={(e) => set('parent_id', e.target.value)}
                                        disabled={!canEdit}
                                        error={errors.parent_id}
                                    >
                                        <option value="">{t('tms.detail.none')}</option>
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
                                            <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.detail.labels')}</label>
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

                                    {(task.viewers?.length ?? 0) > 0 && (
                                        <div className="border-t border-gray-100 pt-3">
                                            <span className="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                {t('tms.detail.lastSeen')}
                                            </span>
                                            <ul className="mt-2 space-y-1">
                                                {task.viewers.map((v) => (
                                                    <li key={v.id} className="flex items-center justify-between gap-2 text-xs text-gray-700">
                                                        <UserChip user={v} name={v.id === user?.id ? t('tms.detail.you') : v.name} />
                                                        <span className="shrink-0 text-gray-400" title={v.last_seen_at}>{formatRelativeTime(v.last_seen_at)}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    <div className="border-t border-gray-100 pt-3">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                {t('tms.detail.watchers', { count: watchers.length })}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={toggleWatch}
                                                disabled={togglingWatch}
                                                className={`rounded px-2 py-0.5 text-xs font-semibold transition ${
                                                    isWatching
                                                        ? 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)] hover:bg-[var(--accent-soft-hover)]'
                                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                                }`}
                                            >
                                                {isWatching ? t('tms.detail.watching') : t('tms.detail.watch')}
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
                                                        <UserChip user={w} />
                                                        {(canEdit || w.id === user?.id) && (
                                                            <button
                                                                type="button"
                                                                onClick={() => removeWatcherUser(w.id)}
                                                                className="rounded-full p-0.5 text-gray-400 hover:bg-gray-200 hover:text-gray-600"
                                                                title={t('tms.detail.removeWatcher')}
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
                                                className="mt-2 block w-full rounded-md border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600 shadow-sm focus:border-[var(--accent)] focus:outline-none"
                                                value=""
                                                onChange={(e) => addWatcherUser(e.target.value)}
                                            >
                                                <option value="">{t('tms.detail.addWatcher')}</option>
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
                                            <dt className="text-xs uppercase tracking-wide text-gray-400">{t('tms.detail.reporter')}</dt>
                                            <dd className="font-medium text-gray-700">{task.reporter ? <UserChip user={task.reporter} /> : '—'}</dd>
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <dt className="text-xs uppercase tracking-wide text-gray-400">{t('tms.detail.created')}</dt>
                                            <dd className="font-medium text-gray-700">
                                                <Timestamp date={task.created_at} />
                                            </dd>
                                        </div>
                                    </dl>
                                </aside>
                            </div>
                            <CustomFieldInputs fields={cfDefs} values={form.custom_fields || {}} onChange={(v) => set('custom_fields', v)} errors={errors} />
                        </form>
                    ) : (
                        <div className="px-6 py-5 min-w-0">
                            {tab === 'comments' && (
                                <CommentThread
                                    task={task}
                                    projectId={projectId}
                                    isManager={isCollabManager}
                                    targetCommentId={initialCommentId}
                                />
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