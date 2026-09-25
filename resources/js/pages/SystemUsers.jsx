import { useCallback, useEffect, useState } from 'react';
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

const emptyForm = { name: '', email: '', password: '' };

export default function SystemUsers() {
    usePageTitle('Platform Users');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Users' }]);
    const toast = useToast();
    const [users, setUsers] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [q, setQ] = useState('');
    const [loading, setLoading] = useState(true);
    const [showCreate, setShowCreate] = useState(false);
    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

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
            toast.success(`Created ${data.user.name}.`);
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
                    <h1 className="text-2xl font-bold text-gray-800">Platform Users</h1>
                    <p className="text-sm text-gray-500">Super-admin accounts on the central system database.</p>
                </div>
                <Button onClick={() => setShowCreate(true)}>New admin</Button>
            </div>

            <form onSubmit={search} className="flex max-w-sm gap-2">
                <input
                    type="search"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search name or email…"
                    className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                />
                <Button type="submit">Search</Button>
            </form>

            <Card>
                {loading ? (
                    <Spinner />
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-gray-400">
                            <tr>
                                <th className="pb-2 font-semibold">Name</th>
                                <th className="pb-2 font-semibold">Email</th>
                                <th className="pb-2 font-semibold">Role</th>
                                <th className="pb-2 text-right font-semibold">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((u) => (
                                <tr key={u.id} className="border-t border-gray-100">
                                    <td className="py-2.5 font-medium text-gray-800">{u.name}</td>
                                    <td className="py-2.5 text-gray-600">{u.email}</td>
                                    <td className="py-2.5"><Badge>admin</Badge></td>
                                    <td className="py-2.5 text-right text-gray-400">
                                        {u.created_at ? new Date(u.created_at).toLocaleDateString() : '—'}
                                    </td>
                                </tr>
                            ))}
                            {users.length === 0 && (
                                <tr><td colSpan={4} className="py-6 text-center text-gray-400">No platform users found.</td></tr>
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

            {showCreate && (
                <Modal title="Create platform admin" onClose={() => setShowCreate(false)}>
                    <form onSubmit={submit} className="space-y-4 px-6 pb-6 pt-4">
                        <Input label="Name" name="name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} required />
                        <Input label="Email" type="email" name="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} error={errors.email} required />
                        <Input label="Password" type="password" name="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} error={errors.password} required />
                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={() => setShowCreate(false)}>Cancel</Button>
                            <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Create admin'}</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </div>
    );
}