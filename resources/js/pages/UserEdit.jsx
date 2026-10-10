import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import AccessExplainer from '../components/AccessExplainer';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

/** One user on their own page: profile, roles, default-user and delete — never inline in the list. */
export default function UserEdit() {
    const { userId } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const { can, user: me } = useAuth();
    const manageable = can('users.manage');

    const [user, setUser] = useState(null);
    const [roles, setRoles] = useState([]);
    const [permissions, setPermissions] = useState([]);
    const [form, setForm] = useState({ name: '', email: '', roles: [] });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    usePageTitle(user?.name ? `${user.name} · User` : 'User');

    useEffect(() => {
        setCrumbs([{ label: 'Users', to: '/users' }, { label: user?.name || 'User' }]);
    }, [setCrumbs, user?.name]);

    useEffect(() => {
        let active = true;
        Promise.all([api.get(`/users/${userId}`), api.get('/roles')])
            .then(([u, r]) => {
                if (!active) return;
                setUser(u.data.user);
                setRoles(r.data.roles);
                setPermissions(r.data.permissions || []);
                setForm({ name: u.data.user.name, email: u.data.user.email, roles: u.data.user.roles });
            })
            .catch((e) => {
                if (e?.response?.status === 403) navigate('/403', { replace: true });
                else if (active) setError('Unable to load this user.');
            })
            .finally(() => active && setLoading(false));
        return () => { active = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [userId]);

    function toggleRole(slug) {
        setForm((f) => ({
            ...f,
            roles: f.roles.includes(slug) ? f.roles.filter((r) => r !== slug) : [...f.roles, slug],
        }));
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await api.put(`/users/${userId}`, { name: form.name, email: form.email });
            const { data } = await api.put(`/users/${userId}/roles`, { roles: form.roles });
            setUser(data.user);
            setForm({ name: data.user.name, email: data.user.email, roles: data.user.roles });
            toast.success('User saved.');
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    async function makeDefault() {
        try {
            const { data } = await api.put(`/users/${userId}/default`);
            setUser((u) => ({ ...u, is_default: true }));
            toast.success(data.message);
        } catch (err) {
            toast.error(fieldErrors(err).form || 'Could not shift the default user.');
        }
    }

    async function remove() {
        if (!window.confirm(`Delete ${user.name}? This cannot be undone.`)) return;
        try {
            await api.delete(`/users/${userId}`);
            toast.success(`${user.name} was deleted.`);
            navigate('/users', { replace: true });
        } catch (err) {
            toast.error(fieldErrors(err).form || 'Could not delete the user.');
        }
    }

    if (loading) return <div className="flex justify-center py-20"><Spinner /></div>;
    if (error) return <div className="mx-auto max-w-md py-20"><Alert>{error}</Alert></div>;

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-2xl font-bold text-gray-900">{user.name}</h2>
                {user.is_default && <Badge tone="accent">Default</Badge>}
            </div>

            <form onSubmit={save} className="space-y-6">
                <Card title="Profile" subtitle="The email is the login; changing it changes how they sign in.">
                    <Alert>{errors.form}</Alert>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Input label="Full name" name="name" required disabled={!manageable} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={errors.name} className="bg-white dark:bg-[#161B26] border border-gray-200 dark:border-[#2F3A4C] text-gray-900 dark:text-[#F3F4F6] placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:border-[var(--accent)] focus:ring-1 focus:ring-[var(--accent)]/30 rounded-lg" />
                        <Input label="Email address" name="email" type="email" required disabled={!manageable} value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} error={errors.email} className="bg-white dark:bg-[#161B26] border border-gray-200 dark:border-[#2F3A4C] text-gray-900 dark:text-[#F3F4F6] placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:border-[var(--accent)] focus:ring-1 focus:ring-[var(--accent)]/30 rounded-lg" />
                    </div>
                </Card>

                <Card title="Roles" subtitle="You can only assign roles whose permissions you hold yourself.">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {roles.map((role) => (
                            <label key={role.slug} className="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 dark:border-[#2F3A4C] px-3 py-2 text-sm hover:bg-gray-50/70 dark:hover:bg-[#1C2433]">
                                <input
                                    type="checkbox"
                                    className="mt-0.5 h-4 w-4 accent-[var(--accent)]"
                                    disabled={!manageable}
                                    checked={form.roles.includes(role.slug)}
                                    onChange={() => toggleRole(role.slug)}
                                />
                                <span className="text-gray-700 dark:text-[#64748B]">
                                    {role.name}
                                    <span className="block text-xs text-gray-400 dark:text-[#64748B]">{role.slug}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    {errors.roles && <p className="mt-2 text-sm text-red-600">{errors.roles}</p>}
                </Card>

                {manageable && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="secondary" onClick={() => navigate('/users')}>Back</Button>
                        <Button type="submit" loading={saving}>Save changes</Button>
                    </div>
                )}
            </form>

            {can('roles.view') && <AccessExplainer userId={userId} permissions={permissions} />}

            {manageable && (
                <Card title="Danger zone">
                    <div className="flex flex-wrap items-center gap-2">
                        {!user.is_default && (
                            <Button
                                variant="subtle"
                                disabled={!user.roles.includes('admin')}
                                title={user.roles.includes('admin') ? 'Make this user the tenant default' : 'Only an admin can be the default user'}
                                onClick={makeDefault}
                            >
                                Make default user
                            </Button>
                        )}
                        {user.id !== me?.id && (
                            <Button variant="danger" disabled={user.is_default} onClick={remove}
                                title={user.is_default ? 'The default user cannot be deleted. Shift the default first.' : 'Delete user'}>
                                Delete user
                            </Button>
                        )}
                    </div>
                </Card>
            )}
        </div>
    );
}
