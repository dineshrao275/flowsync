import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
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

const icons = {
    owner: 'M7 21a4 4 0 01-4-4V5a2 2 0 012-2h1V2h2v1h8V2h2v1h1a2 2 0 012 2v12a4 4 0 01-4 4H7zm0-2h10a2 2 0 002-2V8H5v9a2 2 0 002 2zm1-6h8v2H8v-2zm0-4h8v2H8V7z',
    admin: 'M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4zm-1 10l-2-2-1 1 3 3 5-5-1-1-4 4z',
};

function MemberIcon({ role }) {
    const path = icons[role] || icons.member;
    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d={path} />
        </svg>
    );
}

export default function Workspaces() {
    const { can } = useAuth();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [workspaces, setWorkspaces] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [creating, setCreating] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({ name: '', description: '' });
    const [formErrors, setFormErrors] = useState({});
    const canCreate = can('workspaces.create');

    async function load() {
        try {
            const { data } = await api.get('/workspaces');
            setWorkspaces(data.workspaces);
        } catch {
            setError('Unable to load workspaces.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        load();
        setCrumbs([{ label: 'Workspaces' }]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function createWorkspace(e) {
        e.preventDefault();
        setSaving(true);
        setFormErrors({});
        try {
            const { data } = await api.post('/workspaces', form);
            setWorkspaces((current) => [...current, data.workspace]);
            setCreating(false);
            setForm({ name: '', description: '' });
            toast.success(`${data.workspace.name} created.`);
        } catch (e) {
            setFormErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">Workspaces</h2>
                    <p className="mt-1 text-sm text-gray-500">Workspaces group projects, tasks and members.</p>
                </div>
                {canCreate && (
                    <Button onClick={() => setCreating((open) => !open)}>New workspace</Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {creating && (
                <Card title="New workspace" subtitle="You will be added as its owner.">
                    <form onSubmit={createWorkspace} className="space-y-4">
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <Input
                            label="Name"
                            name="name"
                            placeholder="Product Design"
                            value={form.name}
                            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                            error={formErrors.name}
                            required
                        />
                        <Input
                            label="Description"
                            name="description"
                            placeholder="What is this workspace for?"
                            value={form.description}
                            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                            error={formErrors.description}
                        />
                        <div className="flex gap-2 pt-1">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={saving}>
                                Create workspace
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            {workspaces.length === 0 && !creating ? (
                <Card>
                    <p className="text-sm text-gray-500">No workspaces yet.</p>
                    {canCreate && (
                        <div className="mt-4">
                            <p className="mb-2 text-sm text-gray-600">Create one to start organizing projects and tasks.</p>
                            <Button onClick={() => setCreating(true)}>Create your first workspace</Button>
                        </div>
                    )}
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                    {workspaces.map((workspace, index) => (
                        <Link
                            key={workspace.id}
                            to={`/workspaces/${workspace.id}`}
                            className="animate-fade-in-up block rounded-xl border border-gray-200/70 p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
                            style={{ backgroundColor: 'var(--card-bg)', animationDelay: `${index * 50}ms` }}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <span
                                        className="flex h-10 w-10 items-center justify-center rounded-lg text-sm font-bold text-white shadow-sm"
                                        style={{ backgroundColor: 'var(--accent)' }}
                                    >
                                        {workspace.name.charAt(0).toUpperCase()}
                                    </span>
                                    <div>
                                        <h3 className="text-base font-semibold text-gray-900">{workspace.name}</h3>
                                        <p className="text-xs text-gray-400">{workspace.slug}</p>
                                    </div>
                                </div>
                                <span
                                    className="flex h-8 w-8 items-center justify-center rounded-full text-gray-500"
                                    title={`Your role: ${workspace.my_role || 'none'}`}
                                >
                                    <MemberIcon role={workspace.my_role} />
                                </span>
                            </div>
                            <p className="mt-3 line-clamp-2 min-h-[2.5rem] text-sm text-gray-500">
                                {workspace.description || 'No description'}
                            </p>
                            <div className="mt-4 flex items-center gap-4 text-xs text-gray-400">
                                <span>{workspace.members_count} members</span>
                                <span>{workspace.projects_count} projects</span>
                                <span>{workspace.labels_count} labels</span>
                                {workspace.archived_at && <Badge>archived</Badge>}
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}