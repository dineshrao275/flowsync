import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import AccessExplainer from '../components/AccessExplainer';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

/** One user on their own page: profile, roles, default-user and delete — never inline in the list. */
export default function UserEdit() {
    const { userId } = useParams();
    const { t } = useTranslation();
    const navigate = useNavigate();
    const toast = useToast();
    const confirm = useConfirm();
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

    usePageTitle(user?.name ? `${user.name} · ${t('admin.userEdit.title')}` : t('admin.userEdit.title'));

    useEffect(() => {
        setCrumbs([{ label: t('admin.users.pageTitle'), to: '/users' }, { label: user?.name || t('admin.userEdit.title') }]);
    }, [setCrumbs, t, user?.name]);

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
                else if (active) setError(t('admin.userEdit.loadError'));
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
            toast.success(t('admin.userEdit.saved'));
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
            toast.error(fieldErrors(err).form || t('admin.userEdit.defaultShiftFailed'));
        }
    }

    async function remove() {
        if (!(await confirm({ title: t('admin.userEdit.deleteTitle'), message: t('admin.userEdit.deleteMessage', { name: user.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`/users/${userId}`);
            toast.success(t('admin.userEdit.deleted', { name: user.name }));
            navigate('/users', { replace: true });
        } catch (err) {
            toast.error(fieldErrors(err).form || t('admin.userEdit.deleteFailed'));
        }
    }

    if (loading) return <ContentSkeleton size="lg" />;
    if (error) return <div className="mx-auto max-w-md py-20"><Alert>{error}</Alert></div>;

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-2xl font-bold text-gray-900">{user.name}</h2>
                {user.is_default && <Badge tone="accent">{t('admin.userEdit.defaultBadge')}</Badge>}
            </div>

            <form onSubmit={save} className="space-y-6">
                <Card title={t('admin.userEdit.profile')} subtitle={t('admin.userEdit.profileSubtitle')}>
                    <Alert>{errors.form}</Alert>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Input label={t('admin.users.fullName')} name="name" required disabled={!manageable} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={errors.name} />
                        <Input label={t('admin.users.emailAddress')} name="email" type="email" required disabled={!manageable} value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} error={errors.email} />
                    </div>
                </Card>

                <Card title={t('admin.userEdit.rolesTitle')} subtitle={t('admin.userEdit.rolesSubtitle')}>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {roles.map((role) => (
                            <label key={role.slug} className="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50">
                                <input
                                    type="checkbox"
                                    className="mt-0.5 h-4 w-4 accent-[var(--accent)]"
                                    disabled={!manageable}
                                    checked={form.roles.includes(role.slug)}
                                    onChange={() => toggleRole(role.slug)}
                                />
                                <span className="text-gray-700">
                                    {role.name}
                                    <span className="block text-xs text-gray-400">{role.slug}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    {errors.roles && <p className="mt-2 text-sm text-red-600">{errors.roles}</p>}
                </Card>

                {manageable && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="secondary" onClick={() => navigate('/users')}>{t('admin.userEdit.back')}</Button>
                        <Button type="submit" loading={saving}>{t('admin.userEdit.saveChanges')}</Button>
                    </div>
                )}
            </form>

            {can('roles.view') && <AccessExplainer userId={userId} permissions={permissions} />}

            {manageable && (
                <Card title={t('admin.userEdit.dangerZone')}>
                    <div className="flex flex-wrap items-center gap-2">
                        {!user.is_default && (
                            <Button
                                variant="subtle"
                                disabled={!user.roles.includes('admin')}
                                title={user.roles.includes('admin') ? t('admin.userEdit.makeDefaultTitle') : t('admin.userEdit.makeDefaultAdminOnly')}
                                onClick={makeDefault}
                            >
                                {t('admin.userEdit.makeDefaultUser')}
                            </Button>
                        )}
                        {user.id !== me?.id && (
                            <Button variant="danger" disabled={user.is_default} onClick={remove}
                                title={user.is_default ? t('admin.userEdit.deleteUserDefaultBlocked') : t('admin.userEdit.deleteUserTitle')}>
                                {t('admin.userEdit.deleteUserTitle')}
                            </Button>
                        )}
                    </div>
                </Card>
            )}
        </div>
    );
}
