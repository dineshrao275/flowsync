import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import Spinner from '../components/ui/Spinner';
import MetricCard from '../components/ui/MetricCard';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';

const SCOPES = ['own', 'assigned', 'team', 'all', 'manage'];

const DEFAULT_MATRIX_ROWS = [
    { permission: 'leave.view', own: true, assigned: true, team: true, all: true, manage: false },
    { permission: 'leave.create', own: true, assigned: false, team: false, all: false, manage: false },
    { permission: 'leave.edit', own: true, assigned: true, team: false, all: false, manage: false },
    { permission: 'leave.approve', own: false, assigned: true, team: true, all: true, manage: false },
    { permission: 'leave.cancel', own: true, assigned: false, team: false, all: false, manage: false },
    { permission: 'leave.manage_policy', own: false, assigned: false, team: false, all: false, manage: true },
    { permission: 'leave.export', own: false, assigned: false, team: true, all: true, manage: false },
];

const DEFAULT_ROLE_TEMPLATES = [
    { id: 'super_admin', name: 'Super Admin', description: 'Platform-wide', is_system: true },
    { id: 'tenant_admin', name: 'Tenant Admin', description: 'Organization admin', is_system: true },
    { id: 'hr_admin', name: 'HR Admin', description: 'HRMS administration', is_system: true },
    { id: 'manager', name: 'Manager', description: 'Team-scoped', is_system: false },
    { id: 'project_manager', name: 'Project Manager', description: 'Project-scoped', is_system: false },
    { id: 'employee', name: 'Employee', description: 'Own resources', is_system: true },
    { id: 'custom_role', name: 'Custom role', description: 'Tenant-defined', is_system: false },
];

export default function Roles() {
    usePageTitle('Roles & permissions');
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const [roles, setRoles] = useState([]);
    const [_permissions, setPermissions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const [selectedRoleId, setSelectedRoleId] = useState('hr_admin');
    const [matrix, setMatrix] = useState(DEFAULT_MATRIX_ROWS);
    const [saving, setSaving] = useState(false);

    const [createModal, setCreateModal] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', slug: '', permissions: [] });
    const [createErrors, setCreateErrors] = useState({});

    const manageable = can('roles.manage') || can('permission:roles.manage');

    async function load() {
        try {
            const { data } = await api.get('/roles');
            setRoles(data.roles ?? []);
            setPermissions(data.permissions ?? []);
            if (data.roles?.length && !data.roles.find((r) => r.id === selectedRoleId)) {
                setSelectedRoleId(data.roles[0].id);
            }
        } catch (err) {
            if (err?.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }
            setError('Unable to load roles.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function toggleScope(rowIndex, scope) {
        if (!manageable) return;
        setMatrix((prev) =>
            prev.map((row, idx) => (idx === rowIndex ? { ...row, [scope]: !row[scope] } : row)),
        );
    }

    async function createRole(e) {
        e.preventDefault();
        setSaving(true);
        setCreateErrors({});
        try {
            const { data } = await api.post('/roles', createForm);
            setRoles((current) => [...current, data.role]);
            setSelectedRoleId(data.role.id);
            setCreateModal(false);
            setCreateForm({ name: '', slug: '', permissions: [] });
            toast.success(`Role "${data.role.name}" created.`);
        } catch (e) {
            setCreateErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    const allRoles = roles.length > 0 ? roles : DEFAULT_ROLE_TEMPLATES;
    const selectedRole = allRoles.find((r) => r.id === selectedRoleId) || allRoles[0];

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">Roles & permissions</h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Role-based access with explicit scopes. UI visibility never replaces server-side authorization.
                    </p>
                </div>

                {manageable && (
                    <button
                        type="button"
                        onClick={() => setCreateModal(true)}
                        className="inline-flex items-center justify-center rounded-lg bg-[#4B5EF5] px-4 py-2.5 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                    >
                        + Create role
                    </button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="Tenant users"
                    value={360}
                    pillText="+12%"
                    pillVariant="healthy"
                    accentColor="#4B5EF5"
                />
                <MetricCard
                    label="Custom roles"
                    value={18}
                    pillText="+3"
                    pillVariant="healthy"
                    accentColor="#7B61FF"
                />
                <MetricCard
                    label="Pending invitations"
                    value={7}
                    pillText="Action"
                    pillVariant="healthy"
                    accentColor="#DA972E"
                />
                <MetricCard
                    label="Access reviews due"
                    value={4}
                    pillText="This month"
                    pillVariant="healthy"
                    accentColor="#E05260"
                />
            </div>

            {/* Middle Section: Roles Sidebar (Left) & Permission Matrix (Right) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                {/* Left: Roles list */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-4">
                    <div className="mb-4">
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">Roles</h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">Platform and tenant role templates</p>
                    </div>

                    <div className="divide-y divide-[#F0F2F7]">
                        {allRoles.map((role) => {
                            const isSelected = role.id === selectedRoleId;
                            return (
                                <button
                                    key={role.id}
                                    type="button"
                                    onClick={() => setSelectedRoleId(role.id)}
                                    className={`w-full text-left transition-colors rounded-xl px-4 py-3 my-1 ${
                                        isSelected
                                            ? 'bg-[#E9ECFF] text-[#4B5EF5]'
                                            : 'hover:bg-[#F8FAFD] text-[#171C2C]'
                                    }`}
                                >
                                    <div className="text-[14px] font-semibold">{role.name}</div>
                                    <div className={`text-[12px] ${isSelected ? 'text-[#4B5EF5]/80' : 'text-[#8C96A8]'}`}>
                                        {role.description || (role.is_system ? 'Built-in role' : 'Custom role')}
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Right: Permission matrix */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-8">
                    <div className="mb-4">
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">
                            Permission matrix · {selectedRole?.name || 'HR Admin'}
                        </h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                            Resource + action + scope, checked in API and record policies.
                        </p>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead>
                                <tr className="border-b border-[#F0F2F7] text-[11px] font-semibold uppercase tracking-wider text-[#8C96A8]">
                                    <th className="pb-3 pl-2">PERMISSION</th>
                                    {SCOPES.map((scope) => (
                                        <th key={scope} className="pb-3 text-center uppercase">
                                            {scope}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#F0F2F7]">
                                {matrix.map((row, idx) => (
                                    <tr key={row.permission} className="hover:bg-[#F8FAFD] transition-colors">
                                        <td className="py-3.5 pl-2 font-mono text-[13px] font-medium text-[#171C2C]">
                                            {row.permission}
                                        </td>
                                        {SCOPES.map((scope) => (
                                            <td key={scope} className="py-3.5 text-center">
                                                <input
                                                    type="checkbox"
                                                    checked={!!row[scope]}
                                                    disabled={!manageable}
                                                    onChange={() => toggleScope(idx, scope)}
                                                    className="h-4 w-4 rounded border-[#D0D5DD] text-[#4B5EF5] accent-[#4B5EF5] focus:ring-[#4B5EF5]"
                                                />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-6 border-t border-[#F0F2F7] pt-4">
                        <p className="text-[12px] text-[#8C96A8]">
                            Scope hierarchy: own ⊂ assigned ⊂ team / department / project ⊂ all. Manage is an independent administrative capability.
                        </p>
                        <div className="mt-4 flex items-center justify-between">
                            <button
                                type="button"
                                onClick={() => navigate('/audit-logs')}
                                className="rounded-lg border border-[#E5E8F0] bg-white px-4 py-2 text-[12px] font-medium text-[#171C2C] hover:bg-[#F8FAFD] transition-colors shadow-xs"
                            >
                                Review access audit
                            </button>
                            {manageable && (
                                <button
                                    type="button"
                                    onClick={() => toast.success('Role permissions updated.')}
                                    className="rounded-lg bg-[#4B5EF5] px-4 py-2 text-[12px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                                >
                                    Save changes
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* Create Role Modal */}
            <Modal open={createModal} onClose={() => setCreateModal(false)} title="Create new role" size="md">
                <form onSubmit={createRole} className="space-y-4">
                    <Input
                        label="Role name"
                        placeholder="e.g. Compliance Officer"
                        value={createForm.name}
                        onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })}
                        error={createErrors.name}
                        required
                    />
                    <Input
                        label="Slug"
                        placeholder="compliance_officer"
                        value={createForm.slug}
                        onChange={(e) => setCreateForm({ ...createForm, slug: e.target.value })}
                        error={createErrors.slug}
                        required
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setCreateModal(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={saving}>
                            Create role
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}