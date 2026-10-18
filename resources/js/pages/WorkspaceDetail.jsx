import { useEffect, useState } from 'react';
import MemberPicker from '../components/ui/MemberPicker';
import Avatar from '../components/ui/Avatar';
import ProgressBar from '../components/ui/ProgressBar';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Alert from '../components/ui/Alert';
import { fieldClass } from '../components/ui/fieldStyles';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Tabs from '../components/ui/Tabs';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import TimeSummary from '../components/time/TimeSummary';
import RichTextEditor from '../components/ui/RichTextEditor';
import RichText, { stripRichText } from '../components/ui/RichText';
import usePageTitle from '../hooks/usePageTitle';
import { projectRoleLabel, workspaceRoleLabel } from '../utils/roles';

const roleOptions = [
    { value: 'admin', labelKey: 'tms.workspaceDetail.roleAdmin' },
    { value: 'member', labelKey: 'tms.workspaceDetail.roleMember' },
];

export default function WorkspaceDetail() {
    const { t } = useTranslation();
    const { workspaceId } = useParams();
    const navigate = useNavigate();
    const { user, can, hasModule } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const setCrumbs = useSetCrumbs();
    const [workspace, setWorkspace] = useState(null);
    usePageTitle(workspace?.name || t('tms.workspaceDetail.fallbackTitle'));
    const [members, setMembers] = useState([]);
    const [labels, setLabels] = useState([]);
    const [projects, setProjects] = useState([]);
    const [candidateUsers, setCandidateUsers] = useState([]);
    const [searchParams, setSearchParams] = useSearchParams();
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [updating, setUpdating] = useState(false);

    const [memberForm, setMemberForm] = useState({ user_id: '', role: 'member' });
    const [labelForm, setLabelForm] = useState({ name: '', color: '#2563eb' });
    const [projectForm, setProjectForm] = useState({ name: '', key: '', description: '', template_id: '', board_type: 'kanban' });
    const [templates, setTemplates] = useState([]);

    useEffect(() => {
        if (!hasModule('project_templates')) return;
        api.get('/project-templates').then(({ data }) => setTemplates(data.templates)).catch(() => setTemplates([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    // The settings description is editor-controlled; it follows the loaded
    // workspace (the page remounts per workspace, and a same-id refetch
    // after save already matches what was typed).
    const [settingsDescription, setSettingsDescription] = useState('');

    useEffect(() => {
        if (workspace) setSettingsDescription(workspace.description || '');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [workspace?.id]);
    const [formErrors, setFormErrors] = useState({});
    const [savingMember, setSavingMember] = useState(false);
    const [savingLabel, setSavingLabel] = useState(false);
    const [savingProject, setSavingProject] = useState(false);
    const [creatingProject, setCreatingProject] = useState(false);

    const isManager =
        can('workspaces.manage') || workspace?.my_role === 'owner' || workspace?.my_role === 'admin';
    const isOwner = can('workspaces.manage') || workspace?.my_role === 'owner';
    const archived = Boolean(workspace?.archived_at);
    const canCreateProject = isManager;

    useEffect(() => {
        let active = true;

        Promise.all([
            api.get(`/workspaces/${workspaceId}`),
            api.get(`/workspaces/${workspaceId}/members`),
            api.get(`/workspaces/${workspaceId}/labels`),
            api.get(`/workspaces/${workspaceId}/projects`),
            can('users.view') ? api.get('/users', { params: { all: 1 } }) : Promise.resolve({ data: { users: [] } }),
        ])
            .then(([ws, mem, lab, proj, users]) => {
                if (!active) return;
                setWorkspace(ws.data.workspace);
                setMembers(mem.data.members);
                setLabels(lab.data.labels);
                setProjects(proj.data.projects);
                setCandidateUsers(
                    users.data.users.filter((u) => !mem.data.members.some((m) => m.id === u.id)),
                );
            })
            .catch((err) => {
                if (active && err?.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }
                if (active) setError(t('tms.workspaceDetail.loadFailed'));
            })
            .finally(() => {
                if (active) setLoading(false);
            });

        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [workspaceId]);

    useEffect(() => {
        setCrumbs(
            workspace
                ? [
                    { label: t('tms.workspaceDetail.crumbWorkspaces'), to: '/workspaces' },
                    { label: workspace.name },
                ]
                : [{ label: t('tms.workspaceDetail.crumbWorkspaces'), to: '/workspaces' }],
        );
    }, [workspace, setCrumbs]);

    function updateWorkspace(fields) {
        setUpdating(true);
        setFormErrors({});
        api.put(`/workspaces/${workspaceId}`, fields)
            .then(({ data }) => {
                setWorkspace(data.workspace);
                toast.success(t('tms.workspaceDetail.updated'));
            })
            .catch((e) => setFormErrors(fieldErrors(e)))
            .finally(() => setUpdating(false));
    }

    async function archiveWorkspace() {
        if (!(await confirm({ title: t('tms.workspaceDetail.archiveTitle'), message: t('tms.workspaceDetail.archiveMessage') }))) return;
        try {
            const { data } = await api.post(`/workspaces/${workspaceId}/archive`);
            setWorkspace(data.workspace);
            toast.success(t('tms.workspaceDetail.archived'));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.workspaceDetail.archiveFailed'));
        }
    }

    async function restoreWorkspace() {
        try {
            const { data } = await api.post(`/workspaces/${workspaceId}/restore`);
            setWorkspace(data.workspace);
            toast.success(t('tms.workspaceDetail.restored'));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.workspaceDetail.restoreFailed'));
        }
    }

    async function deleteWorkspace() {
        if (!(await confirm({ title: t('tms.workspaceDetail.deleteTitle'), message: t('tms.workspaceDetail.deleteMessage'), variant: 'danger' }))) return;
        try {
            await api.delete(`/workspaces/${workspaceId}`);
            toast.success(t('tms.workspaceDetail.deleted'));
            navigate('/workspaces');
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.workspaceDetail.deleteFailed'));
        }
    }

    function loadMembers() {
        return Promise.all([
            api.get(`/workspaces/${workspaceId}/members`),
            can('users.view') ? api.get('/users', { params: { all: 1 } }) : Promise.resolve({ data: { users: [] } }),
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
            await api.post(`/workspaces/${workspaceId}/members`, memberForm);
            await loadMembers();
            setMemberForm((f) => ({ ...f, user_id: '' }));
            toast.success(t('tms.workspaceDetail.memberAdded'));
        } catch (e) {
            setFormErrors(fieldErrors(e));
        } finally {
            setSavingMember(false);
        }
    }

    async function changeRole(member, role) {
        try {
            await api.put(`/workspaces/${workspaceId}/members/${member.id}`, { role });
            await loadMembers();
            toast.success(t('tms.workspaceDetail.roleUpdated'));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.workspaceDetail.roleFailed'));
        }
    }

    async function removeMember(member) {
        if (!(await confirm({ title: t('tms.workspaceDetail.removeMemberTitle'), message: t('tms.workspaceDetail.removeMemberMessage', { name: member.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`/workspaces/${workspaceId}/members/${member.id}`);
            await loadMembers();
            toast.success(t('tms.workspaceDetail.memberRemoved', { name: member.name }));
        } catch (e) {
            toast.error(fieldErrors(e).form || t('tms.workspaceDetail.removeFailed'));
        }
    }

    async function addLabel(e) {
        e.preventDefault();
        setSavingLabel(true);
        setFormErrors({});
        try {
            const { data } = await api.post(`/workspaces/${workspaceId}/labels`, labelForm);
            setLabels((l) => [...l, data.label]);
            setLabelForm((f) => ({ ...f, name: '' }));
            toast.success(t('tms.workspaceDetail.labelCreated'));
        } catch (e) {
            setFormErrors(fieldErrors(e));
        } finally {
            setSavingLabel(false);
        }
    }

    async function deleteLabel(label) {
        if (!(await confirm({ title: t('tms.workspaceDetail.deleteLabelTitle'), message: t('tms.workspaceDetail.deleteLabelMessage', { name: label.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`/labels/${label.id}`);
            setLabels((l) => l.filter((x) => x.id !== label.id));
            toast.success(t('tms.workspaceDetail.labelDeleted'));
        } catch {
            toast.error(t('tms.workspaceDetail.deleteLabelFailed'));
        }
    }

    async function createProject(e) {
        e.preventDefault();
        setCreatingProject(false);
        setSavingProject(true);
        setFormErrors({});
        try {
            const payload = {
                name: projectForm.name,
                key: projectForm.key || undefined,
                description: projectForm.description || undefined,
                board_type: projectForm.template_id ? undefined : projectForm.board_type,
            };
            const { data } = projectForm.template_id
                ? await api.post(`/workspaces/${workspaceId}/projects/from-template`, { ...payload, template_id: Number(projectForm.template_id) })
                : await api.post(`/workspaces/${workspaceId}/projects`, payload);
            // The from-template reply is minimal; refetch the list so the new project card is complete.
            if (projectForm.template_id) {
                const list = await api.get(`/workspaces/${workspaceId}/projects`);
                setProjects(list.data.projects);
            } else {
                setProjects((current) => [...current, data.project]);
            }
            setProjectForm({ name: '', key: '', description: '', template_id: '', board_type: 'kanban' });
            toast.success(t('tms.workspaceDetail.projectCreated', { key: data.project.key }));
        } catch (e) {
            setCreatingProject(true);
            setFormErrors(fieldErrors(e));
        } finally {
            setSavingProject(false);
        }
    }

    if (loading) {
        return (
            <ContentSkeleton size="lg" />
        );
    }

    if (error || !workspace) {
        return (
            <div className="max-w-md">
                <Alert>{error || t('tms.workspaceDetail.notFound')}</Alert>
            </div>
        );
    }

    const tabs = [
        { key: 'projects', label: t('tms.workspaceDetail.tabProjects') },
        { key: 'members', label: t('tms.workspaceDetail.tabMembers', { count: members.length }) },
        { key: 'labels', label: t('tms.workspaceDetail.tabLabels', { count: labels.length }) },
        ...(hasModule('time_tracking') ? [{ key: 'time', label: t('tms.workspaceDetail.tabTime') }] : []),
        ...(isOwner ? [{ key: 'settings', label: t('tms.workspaceDetail.tabSettings') }] : []),
    ];

    const tabParam = searchParams.get('tab');
    const activeTab = tabs.some((t) => t.key === tabParam) ? tabParam : 'projects';

    function changeTab(next) {
        if (next === 'projects') {
            setSearchParams({});
        } else {
            setSearchParams({ tab: next });
        }
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-4">
                    <span
                        className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl text-xl font-bold text-white shadow-md"
                        style={{ backgroundColor: 'var(--accent)' }}
                    >
                        {workspace.name.charAt(0).toUpperCase()}
                    </span>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-2xl font-bold text-gray-900">{workspace.name}</h2>
                            {workspace.my_role && <Badge>{workspaceRoleLabel(workspace.my_role)}</Badge>}
                            {archived && <Badge>{t('tms.workspaceDetail.archivedBadge')}</Badge>}
                        </div>
                        {workspace.description ? (
                            <RichText value={workspace.description} className="mt-1 max-h-24 max-w-3xl overflow-y-auto break-words text-gray-500 [overflow-wrap:anywhere]" />
                        ) : (
                            <p className="mt-1 text-sm text-gray-500">{t('tms.workspaceDetail.noDescription')}</p>
                        )}
                        <p className="mt-0.5 text-xs text-gray-400">{workspace.slug}</p>
                    </div>
                </div>
                {archived && isOwner ? (
                    <Button variant="secondary" onClick={restoreWorkspace}>
                        {t('tms.workspaceDetail.restore')}
                    </Button>
                ) : (
                    isManager && (
                        <Button variant="secondary" onClick={archiveWorkspace}>
                            {t('tms.workspaceDetail.archive')}
                        </Button>
                    )
                )}
            </div>

            <Tabs tabs={tabs} active={activeTab} onChange={changeTab} />

            {activeTab === 'projects' && (
                <div className="space-y-5">
                    <Card
                        title={t('tms.workspaceDetail.projectsIn', { name: workspace.name })}
                        actions={
                            canCreateProject &&
                            !archived && (
                                <Button size="sm" variant="secondary" onClick={() => setCreatingProject((open) => !open)}>
                                    {t('tms.workspaceDetail.newProject')}
                                </Button>
                            )
                        }
                    >
                        {creatingProject && (
                            <form onSubmit={createProject} className="mb-5 space-y-4 rounded-lg border border-gray-200 p-4">
                                {formErrors.form && <Alert>{formErrors.form}</Alert>}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Input
                                        label={t('tms.workspaceDetail.projectName')}
                                        name="project-name"
                                        placeholder={t('tms.workspaceDetail.projectNamePlaceholder')}
                                        value={projectForm.name}
                                        onChange={(e) => setProjectForm((f) => ({ ...f, name: e.target.value }))}
                                        error={formErrors.name}
                                        required
                                    />
                                    <Input
                                        label={t('tms.workspaceDetail.projectKey')}
                                        name="project-key"
                                        placeholder={t('tms.workspaceDetail.projectKeyPlaceholder')}
                                        value={projectForm.key}
                                        onChange={(e) => setProjectForm((f) => ({ ...f, key: e.target.value }))}
                                        error={formErrors.key}
                                    />
                                </div>
                                {!projectForm.template_id && (
                                    <div>
                                        <label htmlFor="project-board-type" className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.workspaceDetail.boardType')}</label>
                                        <select id="project-board-type" className={fieldClass} value={projectForm.board_type} onChange={(e) => setProjectForm((f) => ({ ...f, board_type: e.target.value }))}>
                                            {['kanban', 'scrum', 'basic'].map((bt) => <option key={bt} value={bt}>{t(`tms.workspaceDetail.boardTypes.${bt}`)}</option>)}
                                        </select>
                                        <p className="mt-1 text-xs text-gray-500">{t(`tms.workspaceDetail.boardTypeHint.${projectForm.board_type}`)}</p>
                                    </div>
                                )}
                                {templates.length > 0 && (
                                    <div>
                                        <label htmlFor="project-template" className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.templates.startFrom')}</label>
                                        <select id="project-template" className={fieldClass} value={projectForm.template_id} onChange={(e) => setProjectForm((f) => ({ ...f, template_id: e.target.value }))}>
                                            <option value="">{t('tms.templates.blank')}</option>
                                            {templates.map((tpl) => (
                                                <option key={tpl.id} value={tpl.id}>{tpl.name} — {tpl.statuses.join(' → ')}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.workspaceDetail.projectDesc')}</label>
                                    <RichTextEditor
                                        value={projectForm.description}
                                        onChange={(html) => setProjectForm((f) => ({ ...f, description: html }))}
                                        placeholder={t('tms.workspaceDetail.projectDescPlaceholder')}
                                        error={formErrors.description}
                                    />
                                </div>
                                <div className="flex gap-2 pt-1">
                                    <Button type="button" variant="secondary" onClick={() => setCreatingProject(false)}>
                                        {t('tms.workspaceDetail.cancel')}
                                    </Button>
                                    <Button type="submit" loading={savingProject}>
                                        {t('tms.workspaceDetail.createProject')}
                                    </Button>
                                </div>
                            </form>
                        )}

                        {projects.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                {workspace.my_role === 'member' && !can('workspaces.manage')
                                    ? t('tms.workspaceDetail.emptyProjectsMember')
                                    : t('tms.workspaceDetail.emptyProjectsCta')}
                            </p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {projects.map((project) => (
                                    <li key={project.id}>
                                        <Link
                                            to={`/projects/${project.id}`}
                                            className="flex flex-wrap items-center justify-between gap-3 py-4 transition hover:bg-gray-50"
                                        >
                                            <div className="flex items-center gap-3">
                                                <span
                                                    className="flex h-10 w-10 items-center justify-center rounded-lg text-xs font-bold text-white"
                                                    style={{ backgroundColor: 'var(--accent)' }}
                                                >
                                                    {project.key}
                                                </span>
                                                <div>
                                                    <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                                                        {project.name}
                                                        {project.my_role && (
                                                            <Badge>{projectRoleLabel(project.my_role)}</Badge>
                                                        )}
                                                        {project.archived_at && <Badge>{t('tms.workspaceDetail.archivedBadge')}</Badge>}
                                                    </p>
                                                        <p className="mt-0.5 line-clamp-1 text-xs text-gray-400">
                                                            {stripRichText(project.description) || t('tms.workspaceDetail.noDescription')}
                                                        </p>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-4 text-xs text-gray-400">
                                                <span>{t('tms.workspaceDetail.tasksCount', { count: project.tasks_count })}</span>
                                                <span>{t('tms.workspaceDetail.membersCount', { count: project.members_count })}</span>
                                                {project.tasks_count > 0 && (
                                                    <ProgressBar className="w-28" value={project.done_tasks_count ?? 0} max={project.tasks_count} label={t('tms.project.progress')} showPercent />
                                                )}
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            )}

            {activeTab === 'members' && (
                <div className="space-y-5">
                    {isManager && (
                        <Card title={t('tms.workspaceDetail.addMemberTitle')} subtitle={t('tms.workspaceDetail.addMemberSubtitle')}>
                            <form onSubmit={addMember} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="flex-1">
                                    <MemberPicker
                                        users={candidateUsers}
                                        value={memberForm.user_id}
                                        onChange={(id) => setMemberForm((f) => ({ ...f, user_id: id }))}
                                        placeholder={t('tms.workspaceDetail.searchPeople')}
                                    />
                                    {formErrors.user_id && <p className="mt-1.5 text-sm text-red-600">{formErrors.user_id}</p>}
                                </div>
                                <div>
                                    <select
                                        className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-[var(--accent)] focus:outline-none"
                                        value={memberForm.role}
                                        onChange={(e) => setMemberForm((f) => ({ ...f, role: e.target.value }))}
                                    >
                                        {roleOptions.map((r) => (
                                            <option key={r.value} value={r.value}>
                                                {t(r.labelKey)}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <Button type="submit" loading={savingMember}>
                                    {t('tms.workspaceDetail.add')}
                                </Button>
                            </form>
                        </Card>
                    )}

                    <Card title={t('tms.workspaceDetail.membersTitle')}>
                        <ul className="divide-y divide-gray-100">
                            {members.map((member) => {
                                const self = member.id === user?.id;
                                const canDemote = isManager && member.role !== 'owner' && !self;
                                const canRemove = isManager && member.role !== 'owner';
                                return (
                                    <li key={member.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <div className="flex items-center gap-3">
                                            <Avatar name={member.name} photoUrl={member.photo_url} size="lg" />
                                            <div>
                                                <p className="text-sm font-medium text-gray-900">
                                                    {member.name} {self && <span className="text-gray-400">{t('tms.workspaceDetail.you')}</span>}
                                                </p>
                                                <p className="text-xs text-gray-400">{member.email}</p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge>{member.role}</Badge>
                                            {canDemote && (
                                                <select
                                                    className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:outline-none"
                                                    value={member.role}
                                                    onChange={(e) => changeRole(member, e.target.value)}
                                                >
                                                    {roleOptions.map((r) => (
                                                        <option key={r.value} value={r.value}>
                                                            {t(r.labelKey)}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                            {canRemove && (
                                                <Button size="sm" variant="ghost" onClick={() => removeMember(member)}>
                                                    {t('tms.workspaceDetail.remove')}
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

            {activeTab === 'labels' && (
                <div className="space-y-5">
                    {isManager && (
                        <Card title={t('tms.workspaceDetail.newLabelTitle')}>
                            <form onSubmit={addLabel} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="flex-1">
                                    <Input
                                        label={t('tms.workspaceDetail.labelName')}
                                        name="label-name"
                                        placeholder={t('tms.workspaceDetail.labelNamePlaceholder')}
                                        value={labelForm.name}
                                        onChange={(e) => setLabelForm((f) => ({ ...f, name: e.target.value }))}
                                        error={formErrors.name}
                                        required
                                    />
                                </div>
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.workspaceDetail.color')}</label>
                                    <input
                                        type="color"
                                        className="h-[42px] w-16 rounded-lg border border-gray-300 bg-white p-1 shadow-sm focus:outline-none"
                                        value={labelForm.color}
                                        onChange={(e) => setLabelForm((f) => ({ ...f, color: e.target.value }))}
                                    />
                                </div>
                                <Button type="submit" loading={savingLabel}>
                                    {t('tms.workspaceDetail.addLabel')}
                                </Button>
                            </form>
                        </Card>
                    )}

                    <Card title={t('tms.workspaceDetail.labelsTitle')}>
                        {labels.length === 0 ? (
                            <p className="text-sm text-gray-500">{t('tms.workspaceDetail.labelsEmpty')}</p>
                        ) : (
                            <ul className="flex flex-wrap gap-2">
                                {labels.map((label) => (
                                    <li
                                        key={label.id}
                                        className="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm"
                                        style={{ backgroundColor: `${label.color}1a`, color: label.color }}
                                    >
                                        <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: label.color }} />
                                        {label.name}
                                        {isManager && (
                                            <button
                                                type="button"
                                                className="text-inherit opacity-60 transition hover:opacity-100"
                                                onClick={() => deleteLabel(label)}
                                                aria-label={t('tms.workspaceDetail.deleteLabelAria', { name: label.name })}
                                            >
                                                ×
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            )}

            {activeTab === 'time' && (
                <Card title={t('tms.workspaceDetail.timeTitle')} subtitle={t('tms.workspaceDetail.timeSubtitle')}>
                    <TimeSummary url={`/workspaces/${workspace.id}/time-summary`} />
                </Card>
            )}

            {activeTab === 'settings' && isOwner && (
                <Card title={t('tms.workspaceDetail.settingsTitle')} subtitle={`slug: ${workspace.slug}`}>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            updateWorkspace({
                                name: e.currentTarget.name.value,
                                description: settingsDescription || undefined,
                            });
                        }}
                    >
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <Input
                            label={t('tms.workspaceDetail.settingsName')}
                            name="name"
                            defaultValue={workspace.name}
                            error={formErrors.name}
                            required
                        />
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.workspaceDetail.settingsDesc')}</label>
                            <RichTextEditor
                                value={settingsDescription}
                                onChange={setSettingsDescription}
                                placeholder={t('tms.workspaceDetail.settingsDescPlaceholder')}
                                error={formErrors.description}
                            />
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" loading={updating}>
                                {t('tms.workspaceDetail.save')}
                            </Button>
                            <Button type="button" variant="danger" onClick={deleteWorkspace}>
                                {t('tms.workspaceDetail.deleteWorkspace')}
                            </Button>
                        </div>
                    </form>
                </Card>
            )}
        </div>
    );
}