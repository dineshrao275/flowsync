import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
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
import TimeSummary from '../components/time/TimeSummary';
import usePageTitle from '../hooks/usePageTitle';

const ROLE_LABELS = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
};

const PROJECT_ROLE_LABELS = {
    lead: 'Lead',
    developer: 'Developer',
    viewer: 'Viewer',
};

const roleOptions = [
    { value: 'admin', label: 'Admin' },
    { value: 'member', label: 'Member' },
];

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

export default function WorkspaceDetail() {
    const { workspaceId } = useParams();
    const navigate = useNavigate();
    const { user, can } = useAuth();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [workspace, setWorkspace] = useState(null);
    usePageTitle(workspace?.name || 'Workspace');
    const [members, setMembers] = useState([]);
    const [labels, setLabels] = useState([]);
    const [projects, setProjects] = useState([]);
    const [candidateUsers, setCandidateUsers] = useState([]);
    const [searchParams, setSearchParams] = useSearchParams();
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [updating, setUpdating] = useState(false);

    const [memberForm, setMemberForm] = useState({ user_id: '', role: 'member' });
    const [labelForm, setLabelForm] = useState({ name: '', color: '#6366f1' });
    const [projectForm, setProjectForm] = useState({ name: '', key: '', description: '' });
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
            can('users.view') ? api.get('/users') : Promise.resolve({ data: { users: [] } }),
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
                if (active) setError('Unable to load workspace.');
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
                    { label: 'Workspaces', to: '/workspaces' },
                    { label: workspace.name },
                ]
                : [{ label: 'Workspaces', to: '/workspaces' }],
        );
    }, [workspace, setCrumbs]);

    function updateWorkspace(fields) {
        setUpdating(true);
        setFormErrors({});
        api.put(`/workspaces/${workspaceId}`, fields)
            .then(({ data }) => {
                setWorkspace(data.workspace);
                toast.success('Workspace updated.');
            })
            .catch((e) => setFormErrors(fieldErrors(e)))
            .finally(() => setUpdating(false));
    }

    async function archiveWorkspace() {
        if (!window.confirm('Archive this workspace? It will be hidden but not deleted.')) return;
        try {
            const { data } = await api.post(`/workspaces/${workspaceId}/archive`);
            setWorkspace(data.workspace);
            toast.success('Workspace archived.');
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not archive workspace.');
        }
    }

    async function restoreWorkspace() {
        try {
            const { data } = await api.post(`/workspaces/${workspaceId}/restore`);
            setWorkspace(data.workspace);
            toast.success('Workspace restored.');
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not restore workspace.');
        }
    }

    async function deleteWorkspace() {
        if (!window.confirm('Permanently delete this workspace? This cannot be undone.')) return;
        try {
            await api.delete(`/workspaces/${workspaceId}`);
            toast.success('Workspace deleted.');
            navigate('/workspaces');
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not delete workspace. Archive it first?');
        }
    }

    function loadMembers() {
        return Promise.all([
            api.get(`/workspaces/${workspaceId}/members`),
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
            await api.post(`/workspaces/${workspaceId}/members`, memberForm);
            await loadMembers();
            setMemberForm((f) => ({ ...f, user_id: '' }));
            toast.success('Member added.');
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
            toast.success('Role updated.');
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not change role.');
        }
    }

    async function removeMember(member) {
        if (!window.confirm(`Remove ${member.name} from this workspace?`)) return;
        try {
            await api.delete(`/workspaces/${workspaceId}/members/${member.id}`);
            await loadMembers();
            toast.success(`${member.name} removed.`);
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not remove member.');
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
            toast.success('Label created.');
        } catch (e) {
            setFormErrors(fieldErrors(e));
        } finally {
            setSavingLabel(false);
        }
    }

    async function deleteLabel(label) {
        if (!window.confirm(`Delete label "${label.name}"?`)) return;
        try {
            await api.delete(`/labels/${label.id}`);
            setLabels((l) => l.filter((x) => x.id !== label.id));
            toast.success('Label deleted.');
        } catch {
            toast.error('Could not delete label.');
        }
    }

    async function createProject(e) {
        e.preventDefault();
        setCreatingProject(false);
        setSavingProject(true);
        setFormErrors({});
        try {
            const { data } = await api.post(`/workspaces/${workspaceId}/projects`, {
                name: projectForm.name,
                key: projectForm.key || undefined,
                description: projectForm.description || undefined,
            });
            setProjects((current) => [...current, data.project]);
            setProjectForm({ name: '', key: '', description: '' });
            toast.success(`${data.project.key} created.`);
        } catch (e) {
            setCreatingProject(true);
            setFormErrors(fieldErrors(e));
        } finally {
            setSavingProject(false);
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    if (error || !workspace) {
        return (
            <div className="max-w-md">
                <Alert>{error || 'Workspace not found.'}</Alert>
            </div>
        );
    }

    const tabs = [
        { key: 'projects', label: 'Projects' },
        { key: 'members', label: `Members (${members.length})` },
        { key: 'labels', label: `Labels (${labels.length})` },
        { key: 'time', label: 'Time' },
        ...(isOwner ? [{ key: 'settings', label: 'Settings' }] : []),
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
                            {workspace.my_role && <Badge>{ROLE_LABELS[workspace.my_role]?.toLowerCase()}</Badge>}
                            {archived && <Badge>archived</Badge>}
                        </div>
                        <p className="mt-1 text-sm text-gray-500">{workspace.description || 'No description'}</p>
                        <p className="mt-0.5 text-xs text-gray-400">{workspace.slug}</p>
                    </div>
                </div>
                {archived && isOwner ? (
                    <Button variant="secondary" onClick={restoreWorkspace}>
                        Restore
                    </Button>
                ) : (
                    isManager && (
                        <Button variant="secondary" onClick={archiveWorkspace}>
                            Archive
                        </Button>
                    )
                )}
            </div>

            <Tabs tabs={tabs} active={activeTab} onChange={changeTab} />

            {activeTab === 'projects' && (
                <div className="space-y-5">
                    <Card
                        title={`Projects in ${workspace.name}`}
                        actions={
                            canCreateProject &&
                            !archived && (
                                <Button size="sm" variant="secondary" onClick={() => setCreatingProject((open) => !open)}>
                                    New project
                                </Button>
                            )
                        }
                    >
                        {creatingProject && (
                            <form onSubmit={createProject} className="mb-5 space-y-4 rounded-lg border border-gray-200 p-4">
                                {formErrors.form && <Alert>{formErrors.form}</Alert>}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Input
                                        label="Name"
                                        name="project-name"
                                        placeholder="Website Redesign"
                                        value={projectForm.name}
                                        onChange={(e) => setProjectForm((f) => ({ ...f, name: e.target.value }))}
                                        error={formErrors.name}
                                        required
                                    />
                                    <Input
                                        label="Key (optional)"
                                        name="project-key"
                                        placeholder="AUTO (initials used)"
                                        value={projectForm.key}
                                        onChange={(e) => setProjectForm((f) => ({ ...f, key: e.target.value }))}
                                        error={formErrors.key}
                                    />
                                </div>
                                <Input
                                    label="Description"
                                    name="project-description"
                                    placeholder="What is this project about?"
                                    value={projectForm.description}
                                    onChange={(e) => setProjectForm((f) => ({ ...f, description: e.target.value }))}
                                    error={formErrors.description}
                                />
                                <div className="flex gap-2 pt-1">
                                    <Button type="button" variant="secondary" onClick={() => setCreatingProject(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" loading={savingProject}>
                                        Create project
                                    </Button>
                                </div>
                            </form>
                        )}

                        {projects.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                {workspace.my_role === 'member' && !can('workspaces.manage')
                                    ? 'No projects here yet.'
                                    : 'No projects yet. Create one to start tracking work.'}
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
                                                            <Badge>{PROJECT_ROLE_LABELS[project.my_role]?.toLowerCase() || project.my_role}</Badge>
                                                        )}
                                                        {project.archived_at && <Badge>archived</Badge>}
                                                    </p>
                                                    <p className="mt-0.5 line-clamp-1 text-xs text-gray-400">
                                                        {project.description || 'No description'}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-4 text-xs text-gray-400">
                                                <span>{project.tasks_count} tasks</span>
                                                <span>{project.members_count} members</span>
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
                                    </select>
                                    {formErrors.user_id && <p className="mt-1.5 text-sm text-red-600">{formErrors.user_id}</p>}
                                </div>
                                <div>
                                    <select
                                        className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                                        value={memberForm.role}
                                        onChange={(e) => setMemberForm((f) => ({ ...f, role: e.target.value }))}
                                    >
                                        {roleOptions.map((r) => (
                                            <option key={r.value} value={r.value}>
                                                {r.label}
                                            </option>
                                        ))}
                                    </select>
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
                                const canDemote = isManager && member.role !== 'owner' && !self;
                                const canRemove = isManager && member.role !== 'owner';
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
                                            <Badge>{member.role}</Badge>
                                            {canDemote && (
                                                <select
                                                    className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:outline-none"
                                                    value={member.role}
                                                    onChange={(e) => changeRole(member, e.target.value)}
                                                >
                                                    {roleOptions.map((r) => (
                                                        <option key={r.value} value={r.value}>
                                                            {r.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                            {canRemove && (
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

            {activeTab === 'labels' && (
                <div className="space-y-5">
                    {isManager && (
                        <Card title="New label">
                            <form onSubmit={addLabel} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="flex-1">
                                    <Input
                                        label="Name"
                                        name="label-name"
                                        placeholder="bug"
                                        value={labelForm.name}
                                        onChange={(e) => setLabelForm((f) => ({ ...f, name: e.target.value }))}
                                        error={formErrors.name}
                                        required
                                    />
                                </div>
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Color</label>
                                    <input
                                        type="color"
                                        className="h-[42px] w-16 rounded-lg border border-gray-300 bg-white p-1 shadow-sm focus:outline-none"
                                        value={labelForm.color}
                                        onChange={(e) => setLabelForm((f) => ({ ...f, color: e.target.value }))}
                                    />
                                </div>
                                <Button type="submit" loading={savingLabel}>
                                    Add label
                                </Button>
                            </form>
                        </Card>
                    )}

                    <Card title="Labels">
                        {labels.length === 0 ? (
                            <p className="text-sm text-gray-500">No labels yet.</p>
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
                                                aria-label={`Delete ${label.name}`}
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
                <Card title="Time logged" subtitle="Filters apply to all work logs across projects in this workspace.">
                    <TimeSummary url={`/workspaces/${workspace.id}/time-summary`} />
                </Card>
            )}

            {activeTab === 'settings' && isOwner && (
                <Card title="Workspace settings" subtitle={`slug: ${workspace.slug}`}>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            updateWorkspace({
                                name: e.currentTarget.name.value,
                                description: e.currentTarget.description.value,
                            });
                        }}
                    >
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <Input
                            label="Name"
                            name="name"
                            defaultValue={workspace.name}
                            error={formErrors.name}
                            required
                        />
                        <Input
                            label="Description"
                            name="description"
                            defaultValue={workspace.description}
                            error={formErrors.description}
                        />
                        <div className="flex gap-2">
                            <Button type="submit" loading={updating}>
                                Save changes
                            </Button>
                            <Button type="button" variant="danger" onClick={deleteWorkspace}>
                                Delete workspace
                            </Button>
                        </div>
                    </form>
                </Card>
            )}
        </div>
    );
}