import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import Pagination from '../components/ui/Pagination';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import Timestamp from '../components/ui/Timestamp';

const emptyForm = { name: '', email: '', password: '' };

export default function SystemUsers() {
    const { t } = useTranslation();
    usePageTitle(t('admin.systemUsers.pageTitle'));
    useSetCrumbs([{ label: t('admin.platform.breadcrumb'), to: '/admin' }, { label: t('admin.platform.users') }]);
    const toast = useToast();
    const [users, setUsers] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [q, setQ] = useState('');
    const [loading, setLoading] = useState(true);
    const [showCreate, setShowCreate] = useState(false);
    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [personaRoles, setPersonaRoles] = useState([]);
    const [editing, setEditing] = useState(null);
    const [picked, setPicked] = useState([]);

    useEffect(() => {
        api.get('/system/platform-roles').then(({ data }) => setPersonaRoles(data.roles)).catch(() => {});
    }, []);

    async function savePersonas() {
        try {
            const { data } = await api.put(`/system/users/${editing.id}/platform-roles`, { roles: picked });
            toast.success(data.message);
            setEditing(null);
            fetchUsers(pagination?.current_page || 1);
        } catch (e) {
            toast.error(fieldErrors(e).form || fieldErrors(e).roles || t('admin.systemUsers.saveRolesFailed'));
        }
    }

    const fetchUsers = useCallback((page = 1, query = q) => {
        setLoading(true);
        api.get('/system/users', { params: { q: query || undefined, page } })
            .then(({ data }) => {
                setUsers(data.users);
                setPagination(data.pagination);
            })
            .finally(() => setLoading(false));
    }, [q]);

    useEffect(() => fetchUsers(), [fetchUsers]);

    function search(e) {
        e.preventDefault();
        fetchUsers(1, q);
    }

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const { data } = await api.post('/system/users', form);
            toast.success(t('admin.systemUsers.created', { name: data.user.name }));
            setShowCreate(false);
            setForm(emptyForm);
            fetchUsers(1, '');
            setQ('');
        } catch (e) {
            setErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-800">{t('admin.systemUsers.heading')}</h1>
                    <p className="text-sm text-gray-500">{t('admin.systemUsers.subtitle')}</p>
                </div>
                <Button onClick={() => setShowCreate(true)}>{t('admin.systemUsers.newAdmin')}</Button>
            </div>

            <form onSubmit={search} className="flex max-w-sm gap-2">
                <input
                    type="search"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder={t('admin.systemUsers.searchPlaceholder')}
                    className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-[var(--accent)]"
                />
                <Button type="submit">{t('admin.common.search')}</Button>
            </form>

            <Card>
                {loading ? (
                    <Spinner />
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-gray-400">
                            <tr>
                                <th className="pb-2 font-semibold">{t('admin.systemUsers.colName')}</th>
                                <th className="pb-2 font-semibold">{t('admin.systemUsers.colEmail')}</th>
                                <th className="pb-2 font-semibold">{t('admin.systemUsers.colAccess')}</th>
                                <th className="pb-2 text-right font-semibold">{t('admin.systemUsers.colCreated')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((u) => (
                                <tr key={u.id} className="border-t border-gray-100">
                                    <td className="py-2.5 font-medium text-gray-800">{u.name}</td>
                                    <td className="py-2.5 text-gray-600">{u.email}</td>
                                    <td className="py-2.5">
                                        {(u.platform_roles || []).length === 0
                                            ? <Badge>{t('admin.systemUsers.fullAccess')}</Badge>
                                            : u.platform_roles.map((r) => <Badge key={r}>{r}</Badge>)}
                                        <button type="button" className="ml-2 text-xs text-[var(--accent)]"
                                            onClick={() => { setEditing(u); setPicked(u.platform_roles || []); }}>{t('admin.systemUsers.editAccess')}</button>
                                    </td>
                                    <td className="py-2.5 text-right text-gray-400">
                                        <Timestamp date={u.created_at} />
                                    </td>
                                </tr>
                            ))}
                            {users.length === 0 && (
                                <tr><td colSpan={4} className="py-6 text-center text-gray-400">{t('admin.systemUsers.empty')}</td></tr>
                            )}
                        </tbody>
                    </table>
                )}
            </Card>

            {pagination && (
                <Pagination
                    placement="sides"
                    page={pagination.current_page}
                    pages={pagination.last_page}
                    onChange={fetchUsers}
                />
            )}

            {editing && (
                <Modal title={t('admin.systemUsers.accessModalTitle', { name: editing.name })} onClose={() => setEditing(null)}>
                    <div className="space-y-3 px-6 pb-6 pt-4">
                        <p className="text-sm text-gray-500">{t('admin.systemUsers.accessModalHint')}</p>
                        {personaRoles.map((r) => (
                            <label key={r.slug} className="flex items-start gap-2 text-sm text-gray-700">
                                <input type="checkbox" className="mt-1" checked={picked.includes(r.slug)}
                                    onChange={(e) => setPicked(e.target.checked ? [...picked, r.slug] : picked.filter((x) => x !== r.slug))} />
                                <span><span className="font-medium">{r.name}</span><br /><span className="text-xs text-gray-500">{r.description}</span></span>
                            </label>
                        ))}
                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>{t('admin.common.cancel')}</Button>
                            <Button type="button" onClick={savePersonas}>{t('admin.common.save')}</Button>
                        </div>
                    </div>
                </Modal>
            )}

            {showCreate && (
                <Modal title={t('admin.systemUsers.createModalTitle')} onClose={() => setShowCreate(false)}>
                    <form onSubmit={submit} className="space-y-4 px-6 pb-6 pt-4">
                        <Input label={t('admin.common.name')} name="name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} required />
                        <Input label={t('admin.common.email')} type="email" name="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} error={errors.email} required />
                        <Input label={t('admin.systemUsers.password')} type="password" name="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} error={errors.password} required />
                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={() => setShowCreate(false)}>{t('admin.common.cancel')}</Button>
                            <Button type="submit" disabled={saving}>{saving ? t('admin.systemUsers.saving') : t('admin.systemUsers.createAdmin')}</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </div>
    );
}