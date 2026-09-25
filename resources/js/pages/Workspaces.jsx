import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

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
    usePageTitle('Workspaces');
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

            <Table>
                <thead>
                    <tr>
                        <Th>Workspace</Th>
                        <Th>Description</Th>
                        <Th>Your role</Th>
                        <Th align="right">Members</Th>
                        <Th align="right">Projects</Th>
                        <Th align="right">Labels</Th>
                        <Th align="right">Status</Th>
                    </tr>
                </thead>
                <tbody>
                    {workspaces.length === 0 ? (
                        <TableEmpty colSpan={7}>No workspaces yet.</TableEmpty>
                    ) : (
                        workspaces.map((workspace, index) => (
                            <tr
                                key={workspace.id}
                                className="animate-fade-in transition-colors duration-150 hover:bg-gray-50"
                                style={{ animationDelay: `${index * 30}ms` }}
                            >
                                <Td>
                                    <Link
                                        to={`/workspaces/${workspace.id}`}
                                        className="flex items-center gap-3 font-medium text-gray-900 hover:text-indigo-600"
                                    >
                                        <span
                                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-semibold text-white"
                                            style={{ backgroundColor: 'var(--accent)' }}
                                        >
                                            {workspace.name.charAt(0).toUpperCase()}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block truncate">{workspace.name}</span>
                                            <span className="block truncate text-xs font-normal text-gray-400">{workspace.slug}</span>
                                        </span>
                                    </Link>
                                </Td>
                                <Td>
                                    <span className="block max-w-md truncate text-gray-500">
                                        {workspace.description || 'No description'}
                                    </span>
                                </Td>
                                <Td>
                                    <span
                                        className="inline-flex items-center gap-2 text-gray-500"
                                        title={`Your role: ${workspace.my_role || 'none'}`}
                                    >
                                        <MemberIcon role={workspace.my_role} />
                                        <span className="capitalize">{workspace.my_role || 'none'}</span>
                                    </span>
                                </Td>
                                <Td align="right" className="tabular-nums">
                                    {workspace.members_count}
                                </Td>
                                <Td align="right" className="tabular-nums">
                                    {workspace.projects_count}
                                </Td>
                                <Td align="right" className="tabular-nums">
                                    {workspace.labels_count}
                                </Td>
                                <Td align="right">
                                    {workspace.archived_at ? (
                                        <Badge>archived</Badge>
                                    ) : (
                                        <span className="text-xs text-gray-400">active</span>
                                    )}
                                </Td>
                            </tr>
                        ))
                    )}
                </tbody>
            </Table>

            {workspaces.length === 0 && !creating && canCreate && (
                <div className="flex flex-col items-center gap-3 py-2 text-center">
                    <p className="text-sm text-gray-600">Create one to start organizing projects and tasks.</p>
                    <Button onClick={() => setCreating(true)}>Create your first workspace</Button>
                </div>
            )}
        </div>
    );
}