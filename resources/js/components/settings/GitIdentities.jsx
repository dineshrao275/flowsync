import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useResource } from '../../hooks/useResource';
import { useToast } from '../../context/ToastContext';
import Button from '../ui/Button';
import Card from '../ui/Card';
import EmptyState from '../ui/EmptyState';
import Input from '../ui/Input';
import Resource from '../ui/Resource';
import Select from '../ui/Select';
import TableSkeleton from '../ui/TableSkeleton';
import { useConfirm } from '../ui/ConfirmDialog';

/** Admin map of GitHub logins to users. Smart commits act as the mapped user, never as a guess. */
export default function GitIdentities() {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const [login, setLogin] = useState('');
    const [userId, setUserId] = useState('');
    const [errors, setErrors] = useState({});
    const identities = useResource((signal) => api.get('/integrations/git/identities', { signal }).then(({ data }) => data.identities), []);
    const users = useResource((signal) => api.get('/users', { params: { per_page: 100 }, signal }).then(({ data }) => data.users ?? []), []);

    async function add(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post('/integrations/git/identities', { user_id: Number(userId), login });
            toast.success(t('admin.git.identityMapped'));
            setLogin('');
            setUserId('');
            identities.reload();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function remove(identity) {
        const ok = await confirm({ title: t('admin.git.unmapTitle', { login: identity.login }), message: t('admin.git.unmapMessage'), variant: 'danger' });
        if (!ok) return;
        await api.delete(`/integrations/git/identities/${identity.id}`);
        identities.reload();
    }

    return (
        <Card title={t('admin.git.identitiesTitle')} subtitle={t('admin.git.identitiesSubtitle')}>
            <form onSubmit={add} className="mb-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <Input label={t('admin.git.githubLogin')} value={login} onChange={(e) => setLogin(e.target.value)} error={errors.login} placeholder="octocat" required />
                <Select label={t('admin.git.user')} value={userId} onChange={(e) => setUserId(e.target.value)} error={errors.user_id} required>
                    <option value="">{t('admin.git.chooseUser')}</option>
                    {(users.data ?? []).map((u) => <option key={u.id} value={u.id}>{u.name} ({u.email})</option>)}
                </Select>
                <Button type="submit" disabled={!login || !userId}>{t('admin.git.mapLogin')}</Button>
            </form>
            <Resource {...identities} skeleton={<TableSkeleton columns={3} />} errorTitle={t('admin.git.identitiesLoadFailed')}>
                {(rows) => (rows.length === 0 ? (
                    <EmptyState title={t('admin.git.noIdentities')} description={t('admin.git.noIdentitiesHint')} />
                ) : (
                    <ul className="divide-y divide-line">
                        {rows.map((i) => (
                            <li key={i.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <span className="min-w-0 truncate"><span className="font-mono text-fg">@{i.login}</span> <span className="text-fg-muted">→ {i.user?.name ?? '—'}</span></span>
                                <Button size="sm" variant="ghost" onClick={() => remove(i)}>{t('admin.git.unmap')}</Button>
                            </li>
                        ))}
                    </ul>
                ))}
            </Resource>
        </Card>
    );
}
