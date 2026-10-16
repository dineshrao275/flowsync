import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Tabs from '../components/ui/Tabs';
import Pagination from '../components/ui/Pagination';
import ColumnSettingsDrawer from '../components/tasks/ColumnSettingsDrawer';
import ColumnEditModal from '../components/tasks/ColumnEditModal';
import useBoardPreferences from '../hooks/useBoardPreferences';
import Icon from '../components/ui/Icon';
import { Columns } from '@phosphor-icons/react';
import { useAuth } from '../context/AuthContext';
import AutomationPanel from '../components/automation/AutomationPanel';
import SprintsPanel from '../components/sprints/SprintsPanel';
import HierarchyPanel from '../components/hierarchy/HierarchyPanel';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import KanbanBoard from '../components/tasks/KanbanBoard';
import { moveCardInBoard } from '../utils/board';
import { optimistic } from '../utils/optimistic';
import { onRealtimeReconnect } from '../utils/realtimeAuth';
import TaskTable from '../components/tasks/TaskTable';
import RichTextEditor from '../components/ui/RichTextEditor';
import RichText from '../components/ui/RichText';
import FiltersBar from '../components/tasks/FiltersBar';
import CalendarView from '../components/tasks/CalendarView';
import CreateTaskModal from '../components/tasks/CreateTaskModal';
import TaskDetail from '../components/tasks/TaskDetail';
import TimeSummary from '../components/time/TimeSummary';
import usePageTitle from '../hooks/usePageTitle';
import { projectRoleLabel } from '../utils/roles';
import ProjectOverviewTab from '../components/project/ProjectOverviewTab';
import ProjectMembersTab from '../components/project/ProjectMembersTab';
import ProjectWorkflowTab from '../components/project/ProjectWorkflowTab';

const DEFAULT_COLORS = ['#2563eb', '#0ea5e9', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6'];

export default function ProjectDetail() {
    const { t } = useTranslation();
    const { projectId } = useParams();
    const navigate = useNavigate();
    const { user, can, hasModule } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const setCrumbs = useSetCrumbs();
    const [project, setProject] = useState(null);
    usePageTitle(project?.name || t('tms.project.fallbackTitle'));
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
    const [statusForm, setStatusForm] = useState({ name: '', category: 'todo', color: DEFAULT_COLORS[0], wip_limit: null });
    const [issueTypes, setIssueTypes] = useState([]);
    const [issueTypeForm, setIssueTypeForm] = useState({ name: '', description: '', color: '#3b82f6', is_subtask: false });
    const [savingIssueType, setSavingIssueType] = useState(false);
    const [issueTypeErrors, setIssueTypeErrors] = useState({});
    const [formErrors, setFormErrors] = useState({});
    const [savingMember, setSavingMember] = useState(false);
    const [savingStatus, setSavingStatus] = useState(false);
    const [settingsDates, setSettingsDates] = useState(null);

    const [view, setView] = useState('board');
    const [showColumns, setShowColumns] = useState(false);
    const [editingColumn, setEditingColumn] = useState(null);
    const [boardPrefs, updateBoardPrefs] = useBoardPreferences(projectId, Boolean(project));
    const [swimlane, setSwimlane] = useState('none');
    const [filters, setFilters] = useState({});
    const [board, setBoard] = useState(null);
    const [listTasks, setListTasks] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [page, setPage] = useState(1);
    const [taskOptions, setTaskOptions] = useState({ statuses: [], priorities: [], assignees: [], labels: [] });
    const [selectedTask, setSelectedTask] = useState(null);
    const [selectedTaskSection, setSelectedTaskSection] = useState(null);
    const [selectedCommentId, setSelectedCommentId] = useState(null);
    const [showCreate, setShowCreate] = useState(false);
    const [savingTask, setSavingTask] = useState(false);
    const [loadingTasks, setLoadingTasks] = useState(false);
    const [tasksError, setTasksError] = useState(null);
    const [jqlError, setJqlError] = useState(null);

    const isProjectManager = can('workspaces.manage') || project?.my_role === 'lead';
    const archived = Boolean(project?.archived_at);

    const isTaskAdmin = can('workspaces.manage');
    const isLead = project?.my_role === 'lead';
    const isDev = project?.my_role === 'developer';
    // A read-only impersonation session is refused by the server on every write;
    // hide the controls instead of letting each one fail with a 403.
    const readOnlySession = Boolean(user?.impersonating) && user?.impersonation_mode === 'read_only';
    const canCreateTask = !readOnlySession && (isTaskAdmin || isLead || isDev);
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
            api.get('/issue-types'),
            can('users.view') ? api.get('/users') : Promise.resolve({ data: { users: [] } }),
        ])
            .then(([proj, mem, st, ro, it, users]) => {
                if (!active) return;
                setProject(proj.data.project);
                setMembers(mem.data.members);
                setStatuses(st.data.statuses);
                setRoles(ro.data.roles);
                setIssueTypes(it.data.issue_types);
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
                if (active) setError(t('tms.project.loadFailed'));
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
        setSettingsDates(null);
    }, [projectId]);

    useEffect(() => {
        setCrumbs(
            project
                ? [
                    { label: t('tms.project.crumbWorkspaces'), to: '/workspaces' },
                    { label: project.workspace?.name || t('tms.projectsList.workspaceFallback', { id: project.workspace_id }), to: `/workspaces/${project.workspace_id}` },
                    { label: project.name },
                ]
                : [{ label: t('tms.project.crumbWorkspaces'), to: '/workspaces' }],
        );
    }, [project, setCrumbs, t]);

    // Latest request wins: a slow earlier fetch (view toggle, filter change, a
    // realtime refetch) must never overwrite a newer board with stale data.
    const loadSeqRef = useRef(0);

    function loadTasks() {
        const seq = ++loadSeqRef.current;
        // The calendar loads its own month; the board payload still supplies the filter options.
        const requestedView = view === 'calendar' ? 'board' : view;
        const params = {};
        if (view === 'list') {
            params.view = 'list';
            params.page = page;
        }
        if (view === 'board' && swimlane === 'assignee') params.swimlane = 'assignee';
        Object.entries(filters).forEach(([key, value]) => {
            if (value !== '' && value != null) params[key] = value;
        });

        setLoadingTasks(true);
        setTasksError(null);
        setJqlError(null);
        return api
            .get(`/projects/${projectId}/tasks`, { params })
            .then(({ data }) => {
                if (seq !== loadSeqRef.current) return;
                if (requestedView === 'board') {
                    setBoard(data.board);
                } else {
                    setListTasks(data.tasks);
                    setPagination(data.pagination);
                }
                setTaskOptions(data.filters);
            })
            .catch((err) => {
                if (seq !== loadSeqRef.current) return;
                if (err?.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }
                const jqlMessage = err?.response?.status === 422 ? err.response.data?.errors?.jql?.[0] : null;
                if (jqlMessage) {
                    // A bad query is the user's to fix: show it by the box and keep what was on screen.
                    setJqlError(jqlMessage);
                    return;
                }
                setTasksError(t('tms.project.tasksFailed'));
            })
            .finally(() => {
                if (seq === loadSeqRef.current) setLoadingTasks(false);
            });
    }

    useEffect(() => {
        // The automation builder borrows the task filter options (labels, priorities) from the same payload.
        if (!project || (tab !== 'tasks' && tab !== 'automation')) return;
        loadTasks();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId, Boolean(project), tab, view, swimlane, filters, page]);

    // A filter change or a deletion can strand the list past its last page —
    // fall back instead of showing an empty table.
    useEffect(() => {
        if (pagination && view === 'list' && page > pagination.last_page) {
            setPage(pagination.last_page);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pagination]);

    const tabRef = useRef(tab);
    tabRef.current = tab;
    const selectedTaskRef = useRef(selectedTask);
    selectedTaskRef.current = selectedTask;
    const loadTasksRef = useRef(loadTasks);
    loadTasksRef.current = loadTasks;

    useEffect(() => {
        if (!project || !window.Echo) return undefined;
        const channel = window.Echo.private(`project.${projectId}`);
        const onTaskSynced = (event) => {
            if (tabRef.current === 'tasks') {
                loadTasksRef.current();
            }
            if (selectedTaskRef.current && (!event?.task_id || event.task_id === selectedTaskRef.current.id)) {
                api.get(`/projects/${projectId}/tasks/${selectedTaskRef.current.id}`)
                    .then(({ data }) => setSelectedTask(data.task))
                    .catch(() => {});
            }
        };
        channel.listen('.task.synced', onTaskSynced);
        // A task changed while the socket was down is not replayed: re-sync.
        const stopReconnect = onRealtimeReconnect(() => onTaskSynced(null));

        return () => {
            channel.stopListening('.task.synced', onTaskSynced);
            stopReconnect();
            window.Echo.leaveChannel(channel.name);
        };
    }, [projectId, project]);

    function openTask(task, section = null, commentId = null) {
        setSelectedTaskSection(section);
        setSelectedCommentId(commentId);
        api.get(`/projects/${projectId}/tasks/${task.id}`)
            .then(({ data }) => setSelectedTask(data.task))
            .catch((err) => {
                if (err?.response?.status === 403) {
                    toast.error(t('tms.project.noViewPermission'));
                    setSelectedTask(null);
                    setSelectedTaskSection(null);
                    setSelectedCommentId(null);
                } else {
                    setSelectedTask(task);
                }
            });
    }

    const DEEP_SECTIONS = ['comments', 'attachments', 'dependencies', 'time', 'activity'];

    const openedDeepTaskRef = useRef(null);
    const fetchedDeepTaskRef = useRef(null);
    useEffect(() => {
        const deepKey = searchParams.get('task');
        const section = searchParams.get('section');
        const commentId = searchParams.get('comment');
        const deepStateKey = deepKey ? `${deepKey}:${section ?? ''}:${commentId ?? ''}` : null;

        if (!project || tab !== 'tasks' || !deepKey || openedDeepTaskRef.current === deepStateKey) return;
        const pool = view === 'board'
            ? ((board?.statuses ?? board?.swimlanes?.flatMap((lane) => lane.statuses)) ?? []).flatMap((s) => s.tasks)
            : listTasks;
        const found = pool.find((t) => t.key === deepKey);
        if (found) {
            openedDeepTaskRef.current = deepStateKey;
            openTask(found, DEEP_SECTIONS.includes(section) ? section : null, commentId);
            return;
        }
        // Not in this view's pool (subtasks never ride the board; filters
        // can hide anything): resolve by key directly so the drawer opens
        // in either view. One attempt per key — a 404 stays closed.
        if (fetchedDeepTaskRef.current === deepStateKey) return;
        fetchedDeepTaskRef.current = deepStateKey;
        api.get(`/projects/${projectId}/tasks/key/${encodeURIComponent(deepKey)}`)
            .then(({ data }) => {
                openedDeepTaskRef.current = deepStateKey;
                openTask(data.task, DEEP_SECTIONS.includes(section) ? section : null, commentId);
            })
            .catch((err) => {
                if (err?.response?.status === 403) {
                    toast.error(t('tms.project.noViewPermission'));
                } else if (err?.response?.status === 404) {
                    toast.error(t('tms.project.taskNotFound'));
                }
            });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [project, tab, view, board, listTasks, searchParams]);

    // Shared column edits go through the Workflow-tab handlers (same endpoints,
    // same server-side `manageWorkflow` gate), then re-sync the board.
    async function saveColumn(status, fields) {
        try {
            await api.put(`/projects/${projectId}/statuses/${status.id}`, fields);
            const { data } = await api.get(`/projects/${projectId}/statuses`);
            setStatuses(data.statuses);
            toast.success(t('tms.project.statusUpdated'));
            loadTasks();
            return true;
        } catch (e) {
            toast.error(fieldErrors(e).form || fieldErrors(e).name || t('tms.project.statusUpdateFailed'));
            return false;
        }
    }

    async function moveColumn(status, position) {
        await saveColumn(status, { position });
    }

    async function removeColumn(status) {
        await deleteStatus(status);
        loadTasks();
    }

    async function createColumn(fields) {
        try {
            await api.post(`/projects/${projectId}/statuses`, fields);
            const { data } = await api.get(`/projects/${projectId}/statuses`);
            setStatuses(data.statuses);
            toast.success(t('tms.project.statusCreated'));
            loadTasks();
            return true;
        } catch (e) {
            toast.error(fieldErrors(e).name || fieldErrors(e).form || t('tms.project.statusUpdateFailed'));
            return false;
        }
    }

    function changeTab(next) {
        if (next === tab) return;
        setTab(next);
        const params = new URLSearchParams(searchParams.toString());
        params.set('tab', next);
        if (next !== 'tasks') {
            params.delete('task');
            params.delete('section');
            params.delete('comment');
        }
        navigate(`?${params.toString()}`);
    }

    function handleDragEnd({ active, over }) {
        if (!over || active.id === over.id || !board) return;

        const columns = board.statuses ?? board.swimlanes.flatMap((lane) => lane.statuses);

        let statusId;
        let index;
        const colMatch = String(over.id).match(/^col-(\d+)$/);
        if (colMatch) {
            statusId = Number(colMatch[1]);
            index = columns.find((s) => s.id === statusId)?.tasks.length ?? 0;
        } else {
            let found = null;
            columns.forEach((s) => {
                const i = s.tasks.findIndex((t) => t.id === over.id);
                if (i >= 0 && !found) found = { status: s, index: i };
            });
            if (!found) return;
            statusId = found.status.id;
            index = found.index;
        }

        const task = columns.flatMap((s) => s.tasks).find((t) => t.id === active.id);
        if (!task) return;

        const dest = columns.find((s) => s.id === statusId);
        if (task.status_id === statusId && (dest?.tasks.findIndex((t) => t.id === task.id) ?? -1) === index) {
            return;
        }

        // The card lands in its new column immediately; a refusal rolls it back
        // (and the refetch below re-syncs counts/positions with the server).
        optimistic(
            setBoard,
            (current) => moveCardInBoard(current, task.id, statusId, index),
            () => api.post(`/projects/${projectId}/tasks/${task.id}/move`, { status_id: statusId, index }),
        )
            .then(() => {
                toast.success(t('tms.project.moved', { key: task.key }));
                loadTasks();
            })
            .catch((e) => {
                const status = e?.response?.status;
                const message = status === 403
                    ? (e.response.data?.message || t('tms.project.moveDenied'))
                    : (fieldErrors(e).form || t('tms.project.moveFailed'));
                toast.error(message);
                loadTasks(); // snap the card back to where the server says it is
            });
    }

    // Column footer "+ Add task": title only, lands in that column. Re-throws
    // so the board keeps the typed text when the server refuses it.
    async function quickAddTask(statusId, title) {
        try {
            await api.post(`/projects/${projectId}/tasks`, { title, status_id: statusId });
            toast.success(t('tms.project.taskCreated'));
            await loadTasks();
        } catch (e) {
            toast.error(fieldErrors(e).title || fieldErrors(e).form || fieldErrors(e).status_id || t('tms.project.taskCreateFailed'));
            throw e;
        }
    }

    function createTask(data) {
        setSavingTask(true);
        return api
            .post(`/projects/${projectId}/tasks`, data)
            .then(() => {
                setShowCreate(false);
                toast.success(t('tms.project.taskCreated'));
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
                toast.success(t('tms.project.taskUpdated'));
                return loadTasks();
            })
            .catch((e) => {
                throw e;
            })
            .finally(() => setSavingTask(false));
    }

    async function deleteTask() {
        if (!selectedTask) return;
        if (!(await confirm({ title: t('tms.project.deleteTaskTitle'), message: t('tms.project.deleteTaskMessage', { key: selectedTask.key }), variant: 'danger' }))) return;
        setSavingTask(true);
        try {
            await api.delete(`/projects/${projectId}/tasks/${selectedTask.id}`);
            setSelectedTask(null);
            setSelectedTaskSection(null);
            toast.success(t('tms.project.taskDeleted'));
            await loadTasks();
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.project.taskDeleteFailed'));
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
                setSettingsDates(null);
                toast.success(t('tms.project.updated'));
            })
            .catch((e) => setFormErrors(fieldErrors(e)))
            .finally(() => setUpdating(false));
    }

    async function archiveProject() {
        try {
            const { data } = await api.post(`/projects/${projectId}/archive`);
            setProject(data.project);
            toast.success(t('tms.project.archived'));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.project.archiveFailed'));
        }
    }

    async function restoreProject() {
        try {
            const { data } = await api.post(`/projects/${projectId}/restore`);
            setProject(data.project);
            toast.success(t('tms.project.restored'));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.project.restoreFailed'));
        }
    }

    async function deleteProject() {
        if (!(await confirm({ title: t('tms.project.deleteTitle'), message: t('tms.project.deleteMessage'), variant: 'danger' }))) return;
        try {
            await api.delete(`/projects/${projectId}`);
            toast.success(t('tms.project.deleted'));
            navigate(-1);
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.project.deleteFailed'));
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
            toast.success(t('tms.project.memberAdded'));
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
            toast.success(t('tms.project.roleUpdated'));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.project.roleFailed'));
        }
    }

    async function removeMember(member) {
        if (!(await confirm({ title: t('tms.project.removeMemberTitle'), message: t('tms.project.removeMemberMessage', { name: member.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`/projects/${projectId}/members/${member.id}`);
            await loadMemberCandidates();
            toast.success(t('tms.project.memberRemoved', { name: member.name }));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.project.memberRemoveFailed'));
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
            toast.success(t('tms.project.statusCreated'));
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
            toast.success(t('tms.project.statusUpdated'));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.project.statusUpdateFailed'));
        }
    }

    async function moveStatus(status, position) {
        await updateStatus(status, { position });
    }

    async function deleteStatus(status) {
        if (!(await confirm({ title: t('tms.project.deleteStatusTitle'), message: t('tms.project.deleteStatusMessage', { name: status.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`/projects/${projectId}/statuses/${status.id}`);
            const { data } = await api.get(`/projects/${projectId}/statuses`);
            setStatuses(data.statuses);
            toast.success(t('tms.project.statusDeleted'));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.project.statusDeleteFailed'));
        }
    }

    async function loadIssueTypes() {
        try {
            const { data } = await api.get('/issue-types');
            setIssueTypes(data.issue_types);
            setTaskOptions((opts) => ({ ...opts, issue_types: data.issue_types }));
        } catch {
            // ignore
        }
    }

    async function addIssueType(e) {
        e.preventDefault();
        setSavingIssueType(true);
        setIssueTypeErrors({});
        try {
            await api.post('/issue-types', issueTypeForm);
            await loadIssueTypes();
            setIssueTypeForm({ name: '', description: '', color: '#3b82f6', is_subtask: false });
            toast.success(t('tms.project.typeCreated'));
        } catch (e) {
            setIssueTypeErrors(fieldErrors(e));
        } finally {
            setSavingIssueType(false);
        }
    }

    async function updateIssueType(type, fields) {
        try {
            await api.put(`/issue-types/${type.id}`, fields);
            await loadIssueTypes();
            toast.success(t('tms.project.typeUpdated'));
        } catch (e) {
            toast.error(fieldErrors(e).name || fieldErrors(e).form || t('tms.project.typeUpdateFailed'));
        }
    }

    async function deleteIssueType(type) {
        if (!(await confirm({ title: t('tms.project.deleteTypeTitle'), message: t('tms.project.deleteTypeMessage', { name: type.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`/issue-types/${type.id}`);
            await loadIssueTypes();
            toast.success(t('tms.project.typeDeleted'));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.project.typeDeleteFailed'));
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
                <Alert>{error || t('tms.project.notFound')}</Alert>
            </div>
        );
    }

    const tabs = [
        { key: 'overview', label: t('tms.project.tabs.overview') },
        { key: 'tasks', label: t('tms.project.tabs.tasks') },
        { key: 'hierarchy', label: t('tms.project.tabs.hierarchy') },
        { key: 'members', label: t('tms.project.tabs.members', { count: members.length }) },
        { key: 'workflow', label: t('tms.project.tabs.workflow') },
        ...(hasModule('time_tracking') ? [{ key: 'time', label: t('tms.project.tabs.time') }] : []),
        ...(hasModule('sprints') ? [{ key: 'sprints', label: t('tms.project.tabs.sprints') }] : []),
        ...(isProjectManager && hasModule('automation') ? [{ key: 'automation', label: t('tms.project.tabs.automation') }] : []),
        ...(isProjectManager ? [{ key: 'settings', label: t('tms.project.tabs.settings') }] : []),
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
                            {project.my_role && <Badge>{projectRoleLabel(project.my_role)}</Badge>}
                            {archived && <Badge>{t('tms.project.archivedBadge')}</Badge>}
                        </div>
                        {project.description ? (
                            <RichText value={project.description} className="mt-1 text-gray-500" />
                        ) : (
                            <p className="mt-1 text-sm text-gray-500">{t('tms.project.noDescription')}</p>
                        )}
                        <p className="mt-0.5 text-xs text-gray-400">{project.key}</p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    {archived && isProjectManager ? (
                        <Button variant="secondary" onClick={restoreProject}>
                            {t('tms.project.restore')}
                        </Button>
                    ) : (
                        isProjectManager && (
                            <Button variant="secondary" onClick={archiveProject}>
                                {t('tms.project.archive')}
                            </Button>
                        )
                    )}
                </div>
            </div>

            <Tabs tabs={tabs} active={tab} onChange={changeTab} />

            {tab === 'overview' && (
                <ProjectOverviewTab lead={lead} members={members} project={project} statuses={statuses} />
            )}

            {tab === 'tasks' && (
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <div className="flex rounded-lg border border-gray-300 p-0.5">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setView('board');
                                        setPage(1);
                                    }}
                                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${view === 'board' ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'text-gray-600 hover:bg-gray-100'
                                        }`}
                                >
                                    {t('tms.project.board')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setView('list');
                                        setPage(1);
                                    }}
                                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${view === 'list' ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'text-gray-600 hover:bg-gray-100'
                                        }`}
                                >
                                    {t('tms.project.list')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setView('calendar');
                                        setPage(1);
                                    }}
                                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${view === 'calendar' ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'text-gray-600 hover:bg-gray-100'
                                        }`}
                                >
                                    {t('tms.calendar.view')}
                                </button>
                            </div>
                            {view === 'board' && (
                                <select
                                    value={swimlane}
                                    onChange={(e) => setSwimlane(e.target.value)}
                                    className="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-600 shadow-sm focus:outline-none"
                                    title={t('tms.project.swimlaneTitle')}
                                >
                                    <option value="none">{t('tms.project.noSwimlanes')}</option>
                                    <option value="assignee">{t('tms.project.swimlanesByAssignee')}</option>
                                </select>
                            )}
                            {loadingTasks && <Spinner className="h-5 w-5" />}
                        </div>
                        <div className="flex items-center gap-2">
                            {view === 'board' && (
                                <Button type="button" variant="secondary" onClick={() => setShowColumns(true)}>
                                    <Icon as={Columns} size="sm" className="mr-1.5" />
                                    {t('tms.columns.button')}
                                    {boardPrefs.hidden.length > 0 && (
                                        <span className="ml-1.5 rounded-full bg-gray-200 px-1.5 text-xs font-semibold text-gray-700">{boardPrefs.hidden.length}</span>
                                    )}
                                </Button>
                            )}
                            {canCreateTask && (
                                <Button onClick={() => setShowCreate(true)}>{t('tms.project.newTask')}</Button>
                            )}
                        </div>
                    </div>

                    <Card>
                        <FiltersBar
                            filters={filters}
                            options={{ ...taskOptions, sprints: hasModule('sprints'), savedFilters: hasModule('saved_filters'), jql: hasModule('jql') }}
                            jqlError={jqlError}
                            projectId={projectId}
                            canShareFilters={isProjectManager}
                            onChange={(next) => {
                                setFilters(next);
                                setPage(1);
                            }}
                        />
                    </Card>

                    {tasksError ? (
                        <Alert>{tasksError}</Alert>
                    ) : view === 'board' && board ? (
                        <KanbanBoard
                            board={board}
                            canMove={canMoveTask}
                            canEdit={canEditTask}
                            canCreate={canCreateTask}
                            onOpen={openTask}
                            onDragEnd={handleDragEnd}
                            onQuickAdd={quickAddTask}
                            prefs={{ ...boardPrefs, save: updateBoardPrefs }}
                            manage={isProjectManager && !archived ? { onEdit: setEditingColumn, onMove: moveColumn, onDelete: removeColumn, order: statuses.map((s) => s.id) } : null}
                        />
                    ) : view === 'calendar' ? (
                        <CalendarView projectId={projectId} filters={filters} canEdit={canEditTask} onOpen={openTask} />
                    ) : view === 'list' ? (
                        <div className="space-y-3">
                            <TaskTable tasks={listTasks} canEdit={canEditTask} onOpen={openTask} />
                            {pagination && (
                                <Pagination
                                    page={pagination.current_page}
                                    pages={pagination.last_page}
                                    total={pagination.total}
                                    onChange={setPage}
                                />
                            )}
                        </div>
                    ) : null}
                </div>
            )}

            {tab === 'members' && (
                <ProjectMembersTab addMember={addMember} candidateUsers={candidateUsers} changeRole={changeRole} formErrors={formErrors} isProjectManager={isProjectManager} memberForm={memberForm} members={members} removeMember={removeMember} roles={roles} savingMember={savingMember} setMemberForm={setMemberForm} user={user} />
            )}

            {tab === 'hierarchy' && <HierarchyPanel projectId={projectId} />}

            {tab === 'sprints' && hasModule('sprints') && <SprintsPanel projectId={projectId} canPlan={isProjectManager} />}

            {tab === 'automation' && isProjectManager && hasModule('automation') && (
                <AutomationPanel
                    projectId={projectId}
                    members={members}
                    statuses={statuses}
                    labels={taskOptions.labels || []}
                    priorities={taskOptions.priorities || []}
                />
            )}

            {tab === 'workflow' && isProjectManager && (
                <ProjectWorkflowTab addIssueType={addIssueType} addStatus={addStatus} can={can} deleteIssueType={deleteIssueType} deleteStatus={deleteStatus}  formErrors={formErrors} issueTypeErrors={issueTypeErrors} issueTypeForm={issueTypeForm} issueTypes={issueTypes} moveStatus={moveStatus} projectId={projectId} savingIssueType={savingIssueType} savingStatus={savingStatus} setIssueTypeForm={setIssueTypeForm} setStatusForm={setStatusForm} statusForm={statusForm} statuses={statuses} updateIssueType={updateIssueType} updateStatus={updateStatus} />
            )}

            {tab === 'time' && (
                <Card title={t('tms.project.timeTitle')} subtitle={t('tms.project.timeSubtitle')}>
                    <TimeSummary url={`/projects/${projectId}/time-summary`} />
                </Card>
            )}

            {tab === 'settings' && isProjectManager && (
                <Card title={t('tms.project.settingsTitle')} subtitle={t('tms.project.settingsSubtitle', { key: project.key })}>
                    <SettingsForm
                        project={project}
                        settingsDates={settingsDates}
                        onDatesChange={setSettingsDates}
                        formErrors={formErrors}
                        updating={updating}
                        updateProject={updateProject}
                        deleteProject={deleteProject}
                    />
                </Card>
            )}

            <ColumnSettingsDrawer
                open={showColumns}
                onClose={() => setShowColumns(false)}
                statuses={statuses}
                prefs={boardPrefs}
                onPrefs={updateBoardPrefs}
                canManage={isProjectManager && !archived}
                onEdit={(status) => { setShowColumns(false); setEditingColumn(status); }}
                onMove={moveColumn}
                onDelete={removeColumn}
                onAdd={createColumn}
            />
            {editingColumn && (
                <ColumnEditModal status={editingColumn} onClose={() => setEditingColumn(null)} onSave={saveColumn} />
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
                    initialCommentId={selectedCommentId}
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
                        setSelectedCommentId(null);
                        openedDeepTaskRef.current = null;
                        fetchedDeepTaskRef.current = null;
                        const params = new URLSearchParams(searchParams.toString());
                        params.delete('task');
                        params.delete('section');
                        params.delete('comment');
                        navigate(`?${params.toString()}`, { replace: true });
                    }}
                    onUpdate={(data) => updateTask(selectedTask.id, data)}
                    onDelete={deleteTask}
                />
            )}
        </div>
    );
}
/**
 * Project settings form: name/description stay uncontrolled (read on
 * submit, exactly as before) while the two dates are controlled through
 * the shared calendar Input — which has no uncontrolled mode. Dates fall
 * back to the loaded project until typed, and reset on project switch
 * (settingsDates null) and after a successful save.
 */
function SettingsForm({ project, settingsDates, onDatesChange, formErrors, updating, updateProject, deleteProject }) {
    const { t } = useTranslation();
    const dates = settingsDates ?? { start_date: project.start_date ?? '', due_date: project.due_date ?? '' };
    // Editor-controlled description (the form remounts per project, and a
    // same-id refetch after save already matches what was typed).
    const [description, setDescription] = useState(project.description ?? '');

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                updateProject({
                    name: e.currentTarget.name.value,
                    description: description || undefined,
                    start_date: dates.start_date || null,
                    due_date: dates.due_date || null,
                });
            }}
        >
            {formErrors.form && <Alert>{formErrors.form}</Alert>}
            <Input label={t('tms.project.settingsName')} name="name" defaultValue={project.name} error={formErrors.name} required />
            <div>
                <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.project.settingsDesc')}</label>
                <RichTextEditor
                    value={description}
                    onChange={setDescription}
                    placeholder={t('tms.project.settingsDescPlaceholder')}
                    error={formErrors.description}
                />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Input
                    label={t('tms.project.settingsStart')}
                    type="date"
                    value={dates.start_date}
                    onChange={(e) => onDatesChange({ ...dates, start_date: e.target.value })}
                    error={formErrors.start_date}
                />
                <Input
                    label={t('tms.project.settingsEnd')}
                    type="date"
                    value={dates.due_date}
                    onChange={(e) => onDatesChange({ ...dates, due_date: e.target.value })}
                    error={formErrors.due_date}
                />
            </div>
            <div className="flex gap-2">
                <Button type="submit" loading={updating}>
                    {t('tms.project.save')}
                </Button>
                <Button type="button" variant="danger" onClick={deleteProject}>
                    {t('tms.project.deleteProject')}
                </Button>
            </div>
        </form>
    );
}
