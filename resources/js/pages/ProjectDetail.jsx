import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import KanbanBoard from '../components/tasks/KanbanBoard';
import TaskTable from '../components/tasks/TaskTable';
import FiltersBar from '../components/tasks/FiltersBar';
import CreateTaskModal from '../components/tasks/CreateTaskModal';
import TaskDetail from '../components/tasks/TaskDetail';
import TimeSummary from '../components/time/TimeSummary';
import usePageTitle from '../hooks/usePageTitle';

const ROLE_LABELS = {
    lead: 'Lead',
    developer: 'Developer',
    viewer: 'Viewer',
};

const CATEGORY_LABELS = {
    todo: 'To do',
    in_progress: 'In progress',
    done: 'Done',
};

const DEFAULT_COLORS = ['#6366f1', '#0ea5e9', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6'];

function Tabs({ tabs, active, onChange }) {
    return (
        <div className="flex gap-1 border-b border-gray-200">
            {tabs.map((tab) => (
                <button
                    key={tab.key}
                    type="button"
                    onClick={() => onChange(tab.key)}
                    className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition ${active === tab.key
                            ? 'border-indigo-600 text-indigo-600'
                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                        }`}
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}

export default function ProjectDetail() {
    const { projectId } = useParams();
    const navigate = useNavigate();
    const { user, can } = useAuth();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    usePageTitle(project?.name || 'Project');
    const [project, setProject] = useState(null);
    const [members, setMembers] = useState([]);
    const [roles, setRoles] = useState([]);
    const [statuses, setStatuses] = useState([]);
    const [candidateUsers, setCandidateUsers] = useState([]);
    const [searchParams] = useSearchParams();
    const [tab, setTab] = useState(
        () => searchParams.get('tab')
            ?? (searchParams.get('task') ? 'tasks' : 'overview'),
    );
    useEffect(() => {
        const next = searchParams.get('tab')
            ?? (searchParams.get('task') ? 'tasks' : 'overview');
        setTab((cur) => (cur === next ? cur : next));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchParams]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [updating, setUpdating] = useState(false);

    const [memberForm, setMemberForm] = useState({ user_id: '', role_id: '' });
    const [statusForm, setStatusForm] = useState({ name: '', category: 'todo', color: DEFAULT_COLORS[0] });
    const [formErrors, setFormErrors] = useState({});
    const [savingMember, setSavingMember] = useState(false);
    const [savingStatus, setSavingStatus] = useState(false);

    const [view, setView] = useState('board');
    const [filters, setFilters] = useState({});
    const [board, setBoard] = useState(null);
    const [listTasks, setListTasks] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [taskOptions, setTaskOptions] = useState({ statuses: [], priorities: [], assignees: [], labels: [] });
    const [selectedTask, setSelectedTask] = useState(null);
    const [selectedTaskSection, setSelectedTaskSection] = useState(null);
    const [showCreate, setShowCreate] = useState(false);
    const [savingTask, setSavingTask] = useState(false);
    const [loadingTasks, setLoadingTasks] = useState(false);

    const isProjectManager = can('workspaces.manage') || project?.my_role === 'lead';
    const archived = Boolean(project?.archived_at);

    const isTaskAdmin = can('workspaces.manage');
    const isLead = project?.my_role === 'lead';
    const isDev = project?.my_role === 'developer';
    const canCreateTask = isTaskAdmin || isLead || isDev;
    const canEditTask = canCreateTask;
    const canAssignTask = canCreateTask;
    const canMoveTask = canCreateTask;
    const canDeleteTask = isTaskAdmin || isLead;
    const canLogTime = canCreateTask;
    const canManageLogs = isTaskAdmin || isLead;

    useEffect(() => {
        let active = true;

        Promise.all([
            api.get(`/projects/${projectId}`),
            api.get(`/projects/${projectId}/members`),
            api.get(`/projects/${projectId}/statuses`),
            api.get('/project-roles'),
            can('users.view') ? api.get('/users') : Promise.resolve({ data: { users: [] } }),
        ])
            .then(([proj, mem, st, ro, users]) => {
                if (!active) return;
                setProject(proj.data.project);
                setMembers(mem.data.members);
                setStatuses(st.data.statuses);
                setRoles(ro.data.roles);
                setCandidateUsers(
                    users.data.users.filter((u) => !mem.data.members.some((m) => m.id === u.id)),
                );
                if (ro.data.roles.length > 0) {
                    setMemberForm((f) => ({ ...f, role_id: ro.data.roles[0].id }));
                }
            })
            .catch((err) => {
                if (active && err?.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }
                if (active) setError('Unable to load project.');
            })
            .finally(() => {
                if (active) setLoading(false);
            });

        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId]);

    useEffect(() => {
        setCrumbs(
            project
                ? [
                    { label: 'Workspaces', to: '/workspaces' },
                    { label: project.workspace?.name || `Workspace ${project.workspace_id}`, to: `/workspaces/${project.workspace_id}` },
                    { label: project.name },
                ]
                : [{ label: 'Workspaces', to: '/workspaces' }],
        );
    }, [project, setCrumbs]);

    function refresh() {
        return Promise.all([
            api.get(`/projects/${projectId}`),
            api.get(`/projects/${projectId}/members`),
            api.get(`/projects/${projectId}/statuses`),
        ]).then(([proj, mem, st]) => {
            setProject(proj.data.project);
            setMembers(mem.data.members);
            setStatuses(st.data.statuses);
        });
    }

    function loadTasks() {
        const params = {};
        if (view === 'list') params.view = 'list';
        Object.entries(filters).forEach(([key, value]) => {
            if (value !== '' && value != null) params[key] = value;
        });

        setLoadingTasks(true);
        return api
            .get(`/projects/${projectId}/tasks`, { params })
            .then(({ data }) => {
                if (view === 'board') {
                    setBoard(data.board);
                } else {
                    setListTasks(data.tasks);
                    setPagination(data.pagination);
                }
                setTaskOptions(data.filters);
            })
            .catch(() => { })
            .finally(() => setLoadingTasks(false));
    }

    useEffect(() => {
        if (!project || tab !== 'tasks') return;
        loadTasks();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId, tab, view, filters]);

    useEffect(() => {
        if (!project || !window.Echo) return undefined;
        const channel = window.Echo.private(`project.${projectId}`);
        channel.listen('.task.synced', () => {
            if (tab === 'tasks') loadTasks();
        });
        return () => {
            channel.stopListening('.task.synced');
            window.Echo.leaveChannel(channel.name);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId, project]);

    function openTask(task, section = null) {
        setSelectedTaskSection(section);
        api.get(`/projects/${projectId}/tasks/${task.id}`)
            .then(({ data }) => setSelectedTask(data.task))
            .catch(() => setSelectedTask(task));
    }

    const DEEP_SECTIONS = ['comments', 'attachments', 'dependencies', 'time', 'activity'];

    const openedDeepTaskRef = useRef(null);
    useEffect(() => {
        const deepKey = searchParams.get('task');
        if (!project || tab !== 'tasks' || !deepKey || openedDeepTaskRef.current === deepKey) return;
        const pool = view === 'board'
            ? board?.statuses.flatMap((s) => s.tasks) ?? []
            : listTasks;
        const found = pool.find((t) => t.key === deepKey);
        if (found) {
            openedDeepTaskRef.current = deepKey;
            const section = searchParams.get('section');
            openTask(found, DEEP_SECTIONS.includes(section) ? section : null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [project, tab, view, board, listTasks, searchParams]);

    function changeTab(next) {
        if (next === tab) return;
        setTab(next);
        const params = new URLSearchParams(searchParams.toString());
        params.set('tab', next);
        if (next !== 'tasks') {
            params.delete('task');
            params.delete('section');
        }
        navigate(`?${params.toString()}`);
    }

    function handleDragEnd({ active, over }) {
        if (!over || active.id === over.id || !board) return;

        let statusId;
        let index;
        const colMatch = String(over.id).match(/^col-(\d+)$/);
        if (colMatch) {
            statusId = Number(colMatch[1]);
            index = board.statuses.find((s) => s.id === statusId)?.tasks.length ?? 0;
        } else {
            let found = null;
            board.statuses.forEach((s) => {
                const i = s.tasks.findIndex((t) => t.id === over.id);
                if (i >= 0 && !found) found = { status: s, index: i };
            });
            if (!found) return;
            statusId = found.status.id;
            index = found.index;
        }

        const task = board.statuses.flatMap((s) => s.tasks).find((t) => t.id === active.id);
        if (!task) return;

        const dest = board.statuses.find((s) => s.id === statusId);
        if (task.status_id === statusId && (dest?.tasks.findIndex((t) => t.id === task.id) ?? -1) === index) {
            return;
        }

        api.post(`/projects/${projectId}/tasks/${task.id}/move`, { status_id: statusId, index })
            .then(() => {
                toast.success(`${task.key} moved.`);
                loadTasks();
            })
            .catch((e) => toast.error(fieldErrors(e).form || 'Could not move task.'));
    }

    function createTask(data) {
        setSavingTask(true);
        return api
            .post(`/projects/${projectId}/tasks`, data)
            .then(() => {
                setShowCreate(false);
                toast.success('Task created.');
                return loadTasks();
            })
            .catch((e) => {
                throw e;
            })
            .finally(() => setSavingTask(false));
    }

    function updateTask(taskId, data) {
        setSavingTask(true);
        return api
            .put(`/projects/${projectId}/tasks/${taskId}`, data)
            .then(() => {
                setSelectedTask(null);
                setSelectedTaskSection(null);
                toast.success('Task updated.');
                return loadTasks();
            })
            .catch((e) => {
                throw e;
            })
            .finally(() => setSavingTask(false));
    }

    async function deleteTask() {
        if (!selectedTask) return;
        if (!window.confirm(`Delete ${selectedTask.key}? This cannot be undone.`)) return;
        setSavingTask(true);
        try {
            await api.delete(`/projects/${projectId}/tasks/${selectedTask.id}`);
            setSelectedTask(null);
            setSelectedTaskSection(null);
            toast.success('Task deleted.');
            await loadTasks();
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not delete task.');
        } finally {
            setSavingTask(false);
        }
    }

    function updateProject(fields) {
        setUpdating(true);
        setFormErrors({});
        api.put(`/projects/${projectId}`, fields)
            .then(({ data }) => {
                setProject(data.project);
                toast.success('Project updated.');
            })
            .catch((e) => setFormErrors(fieldErrors(e)))
            .finally(() => setUpdating(false));
    }

    async function archiveProject() {
        try {
            const { data } = await api.post(`/projects/${projectId}/archive`);
            setProject(data.project);
            toast.success('Project archived.');
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not archive project.');
        }
    }

    async function restoreProject() {
        try {
            const { data } = await api.post(`/projects/${projectId}/restore`);
            setProject(data.project);
            toast.success('Project restored.');
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not restore project.');
        }
    }

    async function deleteProject() {
        if (!window.confirm('Permanently delete this project? This cannot be undone.')) return;
        try {
            await api.delete(`/projects/${projectId}`);
            toast.success('Project deleted.');
            navigate(-1);
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not delete project.');
        }
    }

    function loadMemberCandidates() {
        return Promise.all([
            api.get(`/projects/${projectId}/members`),
            can('users.view') ? api.get('/users') : Promise.resolve({ data: { users: [] } }),
        ]).then(([mem, users]) => {
            setMembers(mem.data.members);
            setCandidateUsers(
                users.data.users.filter((u) => !mem.data.members.some((m) => m.id === u.id)),
            );
        });
    }

    async function addMember(e) {
        e.preventDefault();
        setSavingMember(true);
        setFormErrors({});
        try {
            await api.post(`/projects/${projectId}/members`, memberForm);
            await loadMemberCandidates();
            setMemberForm((f) => ({ ...f, user_id: '' }));
            toast.success('Member added.');
        } catch (e) {
            setFormErrors(fieldErrors(e));
        } finally {
            setSavingMember(false);
        }
    }

    async function changeRole(member, roleId) {
        try {
            await api.put(`/projects/${projectId}/members/${member.id}`, { role_id: roleId });
            await loadMemberCandidates();
            toast.success('Role updated.');
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not change role.');
        }
    }

    async function removeMember(member) {
        if (!window.confirm(`Remove ${member.name} from this project?`)) return;
        try {
            await api.delete(`/projects/${projectId}/members/${member.id}`);
            await loadMemberCandidates();
            toast.success(`${member.name} removed.`);
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not remove member.');
        }
    }

    async function addStatus(e) {
        e.preventDefault();
        setSavingStatus(true);
        setFormErrors({});
        try {
            await api.post(`/projects/${projectId}/statuses`, statusForm);
            const { data } = await api.get(`/projects/${projectId}/statuses`);
            setStatuses(data.statuses);
            setStatusForm((f) => ({ ...f, name: '' }));
            toast.success('Status created.');
        } catch (e) {
            setFormErrors(fieldErrors(e));
        } finally {
            setSavingStatus(false);
        }
    }

    async function updateStatus(status, fields) {
        try {
            await api.put(`/projects/${projectId}/statuses/${status.id}`, fields);
            const { data } = await api.get(`/projects/${projectId}/statuses`);
            setStatuses(data.statuses);
            toast.success('Status updated.');
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not update status.');
        }
    }

    async function moveStatus(status, position) {
        await updateStatus(status, { position });
    }

    async function deleteStatus(status) {
        if (!window.confirm(`Delete status "${status.name}"? Tasks in it must move first.`)) return;
        try {
            await api.delete(`/projects/${projectId}/statuses/${status.id}`);
            const { data } = await api.get(`/projects/${projectId}/statuses`);
            setStatuses(data.statuses);
            toast.success('Status deleted.');
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not delete status.');
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    if (error || !project) {
        return (
            <div className="max-w-md">
                <Alert>{error || 'Project not found.'}</Alert>
            </div>
        );
    }

    const tabs = [
        { key: 'overview', label: 'Overview' },
        { key: 'tasks', label: 'Tasks' },
        { key: 'members', label: `Members (${members.length})` },
        { key: 'workflow', label: 'Workflow' },
        { key: 'time', label: 'Time' },
        ...(isProjectManager ? [{ key: 'settings', label: 'Settings' }] : []),
    ];

    const lead = members.find((m) => m.role?.slug === 'lead') || members[0];

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-4">
                    <span
                        className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl text-xl font-bold text-white shadow-md"
                        style={{ backgroundColor: 'var(--accent)' }}
                    >
                        {project.key}
                    </span>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-2xl font-bold text-gray-900">{project.name}</h2>
                            {project.my_role && <Badge>{ROLE_LABELS[project.my_role]?.toLowerCase() || project.my_role}</Badge>}
                            {archived && <Badge>archived</Badge>}
                        </div>
                        <p className="mt-1 text-sm text-gray-500">{project.description || 'No description'}</p>
                        <p className="mt-0.5 text-xs text-gray-400">{project.key}</p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    {archived && isProjectManager ? (
                        <Button variant="secondary" onClick={restoreProject}>
                            Restore
                        </Button>
                    ) : (
                        isProjectManager && (
                            <Button variant="secondary" onClick={archiveProject}>
                                Archive
                            </Button>
                        )
                    )}
                </div>
            </div>

            <Tabs tabs={tabs} active={tab} onChange={changeTab} />

            {tab === 'overview' && (
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="space-y-5 lg:col-span-2">
                        <Card title="Details" subtitle={`Workspace · ${project.workspace_id}`}>
                            <dl className="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-400">Key</dt>
                                    <dd className="mt-1 font-semibold text-gray-900">{project.key}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-400">Lead</dt>
                                    <dd className="mt-1 text-gray-700">{lead?.name ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-400">Start date</dt>
                                    <dd className="mt-1 text-gray-700">{project.start_date ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-400">Due date</dt>
                                    <dd className="mt-1 text-gray-700">{project.due_date ?? '—'}</dd>
                                </div>
                            </dl>
                        </Card>
                    </div>
                    <div className="space-y-5">
                        <Card title="Workflow">
                            {statuses.length === 0 ? (
                                <p className="text-sm text-gray-500">No statuses.</p>
                            ) : (
                                <ol className="space-y-2">
                                    {statuses.map((s) => (
                                        <li
                                            key={s.id}
                                            className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                                        >
                                            <span className="flex items-center gap-2 font-medium text-gray-700">
                                                <span className="h-3 w-3 rounded-full" style={{ backgroundColor: s.color || '#cbd5e1' }} />
                                                {s.name}
                                            </span>
                                            <span className="text-xs text-gray-400">{CATEGORY_LABELS[s.category] ?? s.category}</span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                            <div className="mt-4 grid grid-cols-3 gap-3 text-center">
                                <div className="rounded-lg border border-gray-100 px-3 py-2">
                                    <p className="text-xl font-bold text-gray-900">{members.length}</p>
                                    <p className="text-xs text-gray-500">Members</p>
                                </div>
                                <div className="rounded-lg border border-gray-100 px-3 py-2">
                                    <p className="text-xl font-bold text-gray-900">{project.tasks_count ?? 0}</p>
                                    <p className="text-xs text-gray-500">Tasks</p>
                                </div>
                                <div className="rounded-lg border border-gray-100 px-3 py-2">
                                    <p className="text-xl font-bold text-gray-900">{statuses.length}</p>
                                    <p className="text-xs text-gray-500">Statuses</p>
                                </div>
                            </div>
                        </Card>
                    </div>
                </div>
            )}

            {tab === 'tasks' && (
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <div className="flex rounded-lg border border-gray-300 p-0.5">
                                <button
                                    type="button"
                                    onClick={() => setView('board')}
                                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${view === 'board' ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100'
                                        }`}
                                >
                                    Board
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setView('list')}
                                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${view === 'list' ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100'
                                        }`}
                                >
                                    List
                                </button>
                            </div>
                            {loadingTasks && <Spinner className="h-5 w-5" />}
                        </div>
                        {canCreateTask && (
                            <Button onClick={() => setShowCreate(true)}>New task</Button>
                        )}
                    </div>

                    <Card>
                        <FiltersBar filters={filters} options={taskOptions} onChange={setFilters} />
                    </Card>

                    {view === 'board' && board ? (
                        <KanbanBoard
                            board={board}
                            canMove={canMoveTask}
                            canEdit={canEditTask}
                            onOpen={openTask}
                            onDragEnd={handleDragEnd}
                        />
                    ) : view === 'list' ? (
                        <div className="space-y-3">
                            <TaskTable tasks={listTasks} canEdit={canEditTask} onOpen={openTask} />
                            {pagination && (
                                <p className="text-xs text-gray-500">
                                    Page {pagination.current_page} of {pagination.last_page} · {pagination.total} task
                                    {pagination.total === 1 ? '' : 's'}
                                </p>
                            )}
                        </div>
                    ) : null}
                </div>
            )}

            {tab === 'members' && (
                <div className="space-y-5">
                    {isProjectManager && (
                        <Card title="Add member" subtitle="Only users from your tenant can be added.">
                            <form onSubmit={addMember} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="flex-1">
                                    <select
                                        className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                                        value={memberForm.user_id}
                                        onChange={(e) => setMemberForm((f) => ({ ...f, user_id: e.target.value }))}
                                    >
                                        <option value="">Select a user…</option>
                                        {candidateUsers.map((u) => (
                                            <option key={u.id} value={u.id}>
                                                {u.name} ({u.email})
                                            </option>
                                        ))}
                                        {candidateUsers.length === 0 && <option value="">No eligible users</option>}
                                    </select>
                                    {formErrors.user_id && <p className="mt-1.5 text-sm text-red-600">{formErrors.user_id}</p>}
                                </div>
                                <div>
                                    <select
                                        className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                                        value={memberForm.role_id}
                                        onChange={(e) => setMemberForm((f) => ({ ...f, role_id: Number(e.target.value) }))}
                                    >
                                        {roles.map((r) => (
                                            <option key={r.id} value={r.id}>
                                                {r.name}
                                            </option>
                                        ))}
                                    </select>
                                    {formErrors.role_id && <p className="mt-1.5 text-sm text-red-600">{formErrors.role_id}</p>}
                                </div>
                                <Button type="submit" loading={savingMember}>
                                    Add
                                </Button>
                            </form>
                        </Card>
                    )}

                    <Card title="Members">
                        <ul className="divide-y divide-gray-100">
                            {members.map((member) => {
                                const self = member.id === user?.id;
                                const canManage = isProjectManager && !self;
                                return (
                                    <li key={member.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <div className="flex items-center gap-3">
                                            <span
                                                className="flex h-9 w-9 items-center justify-center rounded-full text-xs font-bold text-white"
                                                style={{ backgroundColor: 'var(--accent)' }}
                                            >
                                                {member.name.charAt(0).toUpperCase()}
                                            </span>
                                            <div>
                                                <p className="text-sm font-medium text-gray-900">
                                                    {member.name} {self && <span className="text-gray-400">(you)</span>}
                                                </p>
                                                <p className="text-xs text-gray-400">{member.email}</p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {member.role ? (
                                                canManage ? (
                                                    <select
                                                        className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:outline-none"
                                                        value={member.role.id}
                                                        onChange={(e) => changeRole(member, Number(e.target.value))}
                                                    >
                                                        {roles.map((r) => (
                                                            <option key={r.id} value={r.id}>
                                                                {r.name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                ) : (
                                                    <Badge>{ROLE_LABELS[member.role.slug]?.toLowerCase() || member.role.name.toLowerCase()}</Badge>
                                                )
                                            ) : (
                                                <Badge>member</Badge>
                                            )}
                                            {canManage && (
                                                <Button size="sm" variant="ghost" onClick={() => removeMember(member)}>
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </Card>
                </div>
            )}

            {tab === 'workflow' && isProjectManager && (
                <div className="space-y-5">
                    <Card title="New status" subtitle="Ordered by position. 'Done' category finishes the flow.">
                        <form onSubmit={addStatus} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div className="flex-1">
                                <Input
                                    label="Name"
                                    name="status-name"
                                    placeholder="Blocked"
                                    value={statusForm.name}
                                    onChange={(e) => setStatusForm((f) => ({ ...f, name: e.target.value }))}
                                    error={formErrors.name}
                                    required
                                />
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-gray-700">Category</label>
                                <select
                                    className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                                    value={statusForm.category}
                                    onChange={(e) => setStatusForm((f) => ({ ...f, category: e.target.value }))}
                                >
                                    <option value="todo">To do</option>
                                    <option value="in_progress">In progress</option>
                                    <option value="done">Done</option>
                                </select>
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-gray-700">Color</label>
                                <input
                                    type="color"
                                    className="h-[42px] w-16 rounded-lg border border-gray-300 bg-white p-1 shadow-sm focus:outline-none"
                                    value={statusForm.color}
                                    onChange={(e) => setStatusForm((f) => ({ ...f, color: e.target.value }))}
                                />
                            </div>
                            <Button type="submit" loading={savingStatus}>
                                Add status
                            </Button>
                        </form>
                    </Card>

                    <Card title="Workflow" subtitle="Reorder with the arrows; renamed/recategorized inline.">
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <ol className="space-y-2">
                            {statuses.map((status, index) => (
                                <li
                                    key={status.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2"
                                >
                                    <div className="flex items-center gap-2">
                                        <div className="flex flex-col">
                                            <button
                                                type="button"
                                                className="text-gray-400 transition hover:text-gray-700 disabled:opacity-30"
                                                disabled={index === 0}
                                                onClick={() => moveStatus(status, status.position - 1)}
                                                aria-label={`Move ${status.name} up`}
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                className="text-gray-400 transition hover:text-gray-700 disabled:opacity-30"
                                                disabled={index === statuses.length - 1}
                                                onClick={() => moveStatus(status, status.position + 1)}
                                                aria-label={`Move ${status.name} down`}
                                            >
                                                ↓
                                            </button>
                                        </div>
                                        <input
                                            type="text"
                                            className="w-40 rounded-lg border border-transparent bg-transparent px-2 py-1 text-sm font-semibold text-gray-800 focus:border-indigo-300 focus:bg-white"
                                            defaultValue={status.name}
                                            onBlur={(e) => {
                                                if (e.target.value !== status.name && e.target.value.trim()) {
                                                    updateStatus(status, { name: e.target.value.trim() });
                                                }
                                            }}
                                        />
                                        <span className="h-3 w-3 rounded-full" style={{ backgroundColor: status.color || '#cbd5e1' }} />
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <select
                                            className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:outline-none"
                                            value={status.category}
                                            onChange={(e) => updateStatus(status, { category: e.target.value })}
                                        >
                                            <option value="todo">To do</option>
                                            <option value="in_progress">In progress</option>
                                            <option value="done">Done</option>
                                        </select>
                                        {status.is_default && <Badge>default</Badge>}
                                        <Button size="sm" variant="ghost" onClick={() => deleteStatus(status)}>
                                            Delete
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </Card>
                </div>
            )}

            {tab === 'time' && (
                <Card title="Time logged" subtitle="Filters apply to all work logs across this project.">
                    <TimeSummary url={`/projects/${projectId}/time-summary`} />
                </Card>
            )}

            {tab === 'settings' && isProjectManager && (
                <Card title="Project settings" subtitle={`key: ${project.key}`}>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            updateProject({
                                name: e.currentTarget.name.value,
                                description: e.currentTarget.description.value,
                                start_date: e.currentTarget.start_date.value || null,
                                due_date: e.currentTarget.due_date.value || null,
                            });
                        }}
                    >
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <Input label="Name" name="name" defaultValue={project.name} error={formErrors.name} required />
                        <Input
                            label="Description"
                            name="description"
                            defaultValue={project.description}
                            error={formErrors.description}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-gray-700">Start date</label>
                                <input
                                    type="date"
                                    name="start_date"
                                    defaultValue={project.start_date}
                                    className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                                />
                                {formErrors.start_date && <p className="mt-1.5 text-sm text-red-600">{formErrors.start_date}</p>}
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-gray-700">Due date</label>
                                <input
                                    type="date"
                                    name="due_date"
                                    defaultValue={project.due_date}
                                    className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                                />
                                {formErrors.due_date && <p className="mt-1.5 text-sm text-red-600">{formErrors.due_date}</p>}
                            </div>
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" loading={updating}>
                                Save changes
                            </Button>
                            <Button type="button" variant="danger" onClick={deleteProject}>
                                Delete project
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            {showCreate && (
                <CreateTaskModal
                    options={taskOptions}
                    topLevelTasks={board?.statuses.flatMap((s) => s.tasks) ?? []}
                    projectKey={project.key}
                    saving={savingTask}
                    onClose={() => setShowCreate(false)}
                    onCreate={createTask}
                />
            )}

            {selectedTask && (
                <TaskDetail
                    task={selectedTask}
                    projectId={projectId}
                    projectKey={project.key}
                    options={taskOptions}
                    topLevelTasks={board?.statuses.flatMap((s) => s.tasks) ?? []}
                    initialSection={selectedTaskSection}
                    canEdit={canEditTask}
                    canAssign={canAssignTask}
                    canDelete={canDeleteTask}
                    canMove={canMoveTask}
                    isCollabManager={canCreateTask}
                    canLog={canLogTime}
                    canManageLogs={canManageLogs}
                    saving={savingTask}
                    onClose={() => {
                        setSelectedTask(null);
                        setSelectedTaskSection(null);
                    }}
                    onUpdate={(data) => updateTask(selectedTask.id, data)}
                    onDelete={deleteTask}
                />
            )}
        </div>
    );
}