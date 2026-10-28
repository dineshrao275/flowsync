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
                if (!active) return;
                setError(err.response?.data?.message || t('tms.workspaceDetail.failedToLoad'));
            })
            .finally(() => {
                if (active) setLoading(false);
            });

        return () => {
            active = false;
        };
    }, [workspaceId, can, t]);

    useEffect(() => {
        if (!workspace) return;
        setCrumbs([
            { label: t('tms.workspaceDetail.crumbsWorkspaces'), to: '/workspaces' },
            { label: workspace.name },
        ]);
    }, [workspace, setCrumbs, t]);

    const activeTab = searchParams.get('tab') || 'projects';
    const changeTab = (key) => {
        setSearchParams((prev) => {
            const next = new URLSearchParams(prev);
            if (key === 'projects') next.delete('tab');
            else next.set('tab', key);
            return next;
        });
    };

    const addMember = async (e) => {
        e.preventDefault();
        setSavingMember(true);
        setFormErrors({});

        try {
            const res = await api.post(`/workspaces/${workspaceId}/members`, memberForm);
            setMembers((prev) => [...prev, res.data.member]);
            setCandidateUsers((prev) => prev.filter((u) => u.id !== Number(memberForm.user_id)));
            setMemberForm({ user_id: '', role: 'member' });
            toast.success(t('tms.workspaceDetail.memberAdded'));
        } catch (err) {
            setFormErrors(fieldErrors(err));
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToAddMember'));
        } finally {
            setSavingMember(false);
        }
    };

    const changeRole = async (member, role) => {
        try {
            const res = await api.put(`/workspaces/${workspaceId}/members/${member.id}`, { role });
            setMembers((prev) => prev.map((m) => (m.id === member.id ? res.data.member : m)));
            toast.success(t('tms.workspaceDetail.roleUpdated'));
        } catch (err) {
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToUpdateRole'));
        }
    };

    const removeMember = async (member) => {
        const ok = await confirm({
            title: t('tms.workspaceDetail.removeConfirmTitle'),
            message: t('tms.workspaceDetail.removeConfirmMessage', { name: member.name }),
            confirmText: t('tms.workspaceDetail.removeConfirmButton'),
            confirmVariant: 'danger',
        });
        if (!ok) return;

        try {
            await api.delete(`/workspaces/${workspaceId}/members/${member.id}`);
            setMembers((prev) => prev.filter((m) => m.id !== member.id));
            setCandidateUsers((prev) => [...prev, member]);
            toast.success(t('tms.workspaceDetail.memberRemoved'));
        } catch (err) {
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToRemoveMember'));
        }
    };

    const addLabel = async (e) => {
        e.preventDefault();
        setSavingLabel(true);
        setFormErrors({});

        try {
            const res = await api.post(`/workspaces/${workspaceId}/labels`, labelForm);
            setLabels((prev) => [...prev, res.data.label]);
            setLabelForm({ name: '', color: '#2563eb' });
            toast.success(t('tms.workspaceDetail.labelCreated'));
        } catch (err) {
            setFormErrors(fieldErrors(err));
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToCreateLabel'));
        } finally {
            setSavingLabel(false);
        }
    };

    const deleteLabel = async (label) => {
        const ok = await confirm({
            title: t('tms.workspaceDetail.deleteLabelConfirmTitle'),
            message: t('tms.workspaceDetail.deleteLabelConfirmMessage', { name: label.name }),
            confirmText: t('tms.workspaceDetail.deleteLabelConfirmButton'),
            confirmVariant: 'danger',
        });
        if (!ok) return;

        try {
            await api.delete(`/labels/${label.id}`);
            setLabels((prev) => prev.filter((l) => l.id !== label.id));
            toast.success(t('tms.workspaceDetail.labelDeleted'));
        } catch (err) {
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToDeleteLabel'));
        }
    };

    const createProject = async (e) => {
        e.preventDefault();
        setSavingProject(true);
        setFormErrors({});

        try {
            const res = await api.post('/projects', {
                ...projectForm,
                workspace_id: workspaceId,
            });
            setProjects((prev) => [res.data.project, ...prev]);
            setProjectForm({ name: '', key: '', description: '', template_id: '', board_type: 'kanban' });
            setCreatingProject(false);
            toast.success(t('tms.workspaceDetail.projectCreated'));
            navigate(`/projects/${res.data.project.id}`);
        } catch (err) {
            setFormErrors(fieldErrors(err));
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToCreateProject'));
        } finally {
            setSavingProject(false);
        }
    };

    const updateWorkspace = async (patch) => {
        setUpdating(true);
        setFormErrors({});

        try {
            const res = await api.put(`/workspaces/${workspaceId}`, patch);
            setWorkspace(res.data.workspace);
            toast.success(t('tms.workspaceDetail.workspaceUpdated'));
        } catch (err) {
            setFormErrors(fieldErrors(err));
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToUpdateWorkspace'));
        } finally {
            setUpdating(false);
        }
    };

    const archiveWorkspace = async () => {
        const ok = await confirm({
            title: t('tms.workspaceDetail.archiveConfirmTitle'),
            message: t('tms.workspaceDetail.archiveConfirmMessage', { name: workspace.name }),
            confirmText: t('tms.workspaceDetail.archiveConfirmButton'),
            confirmVariant: 'danger',
        });
        if (!ok) return;

        try {
            const res = await api.post(`/workspaces/${workspaceId}/archive`);
            setWorkspace(res.data.workspace);
            toast.success(t('tms.workspaceDetail.workspaceArchived'));
        } catch (err) {
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToArchiveWorkspace'));
        }
    };

    const restoreWorkspace = async () => {
        try {
            const res = await api.post(`/workspaces/${workspaceId}/restore`);
            setWorkspace(res.data.workspace);
            toast.success(t('tms.workspaceDetail.workspaceRestored'));
        } catch (err) {
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToRestoreWorkspace'));
        }
    };

    const deleteWorkspace = async () => {
        const ok = await confirm({
            title: t('tms.workspaceDetail.deleteWorkspaceConfirmTitle'),
            message: t('tms.workspaceDetail.deleteWorkspaceConfirmMessage', { name: workspace.name }),
            confirmText: t('tms.workspaceDetail.deleteWorkspaceConfirmButton'),
            confirmVariant: 'danger',
        });
        if (!ok) return;

        try {
            await api.delete(`/workspaces/${workspaceId}`);
            toast.success(t('tms.workspaceDetail.workspaceDeleted'));
            navigate('/workspaces');
        } catch (err) {
            toast.error(err.response?.data?.message || t('tms.workspaceDetail.failedToDeleteWorkspace'));
        }
    };

    if (loading) {
        return <ContentSkeleton size="lg" />;
    }

    if (error || !workspace) {
        return (
            <Card>
                <div className="py-6 text-center">
                    <p className="text-sm text-red-600">{error || t('tms.workspaceDetail.workspaceNotFound')}</p>
                </div>
            </Card>
        );
    }

    const tabs = [
        { key: 'projects', label: t('tms.workspaceDetail.tabProjects'), count: projects.length },
        { key: 'members', label: t('tms.workspaceDetail.tabMembers'), count: members.length },
        { key: 'labels', label: t('tms.workspaceDetail.tabLabels'), count: labels.length },
        { key: 'time', label: t('tms.workspaceDetail.tabTime') },
        ...(isOwner ? [{ key: 'settings', label: t('tms.workspaceDetail.tabSettings') }] : []),
    ];

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <span
                        className="flex h-12 w-12 items-center justify-center rounded-xl text-lg font-bold text-white shadow-sm"
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