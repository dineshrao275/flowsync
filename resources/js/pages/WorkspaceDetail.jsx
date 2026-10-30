import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Button from '../components/ui/Button';
import Tabs from '../components/ui/Tabs';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import TimeSummary from '../components/time/TimeSummary';
import RichText from '../components/ui/RichText';
import usePageTitle from '../hooks/usePageTitle';
import { workspaceRoleLabel } from '../utils/roles';
import { WorkspaceProjectsTab } from '../components/workspace/WorkspaceProjectsTab';
import { WorkspaceMembersTab } from '../components/workspace/WorkspaceMembersTab';
import { WorkspaceLabelsTab } from '../components/workspace/WorkspaceLabelsTab';
import { WorkspaceSettingsTab } from '../components/workspace/WorkspaceSettingsTab';

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
    }, [workspace, setCrumbs, t]);

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
                <WorkspaceProjectsTab
                    workspace={workspace}
                    projects={projects}
                    canCreateProject={canCreateProject}
                    archived={archived}
                    creatingProject={creatingProject}
                    setCreatingProject={setCreatingProject}
                    createProject={createProject}
                    projectForm={projectForm}
                    setProjectForm={setProjectForm}
                    formErrors={formErrors}
                    templates={templates}
                    savingProject={savingProject}
                />
            )}

            {activeTab === 'members' && (
                <WorkspaceMembersTab
                    isManager={isManager}
                    candidateUsers={candidateUsers}
                    memberForm={memberForm}
                    setMemberForm={setMemberForm}
                    formErrors={formErrors}
                    roleOptions={roleOptions}
                    savingMember={savingMember}
                    addMember={addMember}
                    members={members}
                    user={user}
                    changeRole={changeRole}
                    removeMember={removeMember}
                />
            )}

            {activeTab === 'labels' && (
                <WorkspaceLabelsTab
                    isManager={isManager}
                    addLabel={addLabel}
                    labelForm={labelForm}
                    setLabelForm={setLabelForm}
                    formErrors={formErrors}
                    savingLabel={savingLabel}
                    labels={labels}
                    deleteLabel={deleteLabel}
                />
            )}

            {activeTab === 'time' && (
                <Card title={t('tms.workspaceDetail.timeTitle')} subtitle={t('tms.workspaceDetail.timeSubtitle')}>
                    <TimeSummary url={`/workspaces/${workspace.id}/time-summary`} />
                </Card>
            )}

            {activeTab === 'settings' && isOwner && (
                <WorkspaceSettingsTab
                    workspace={workspace}
                    updateWorkspace={updateWorkspace}
                    settingsDescription={settingsDescription}
                    setSettingsDescription={setSettingsDescription}
                    formErrors={formErrors}
                    updating={updating}
                    deleteWorkspace={deleteWorkspace}
                />
            )}
        </div>
    );
}