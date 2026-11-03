import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useResource } from '../../hooks/useResource';
import { useToast } from '../../context/ToastContext';
import Alert from '../ui/Alert';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Card from '../ui/Card';
import EmptyState from '../ui/EmptyState';
import Input from '../ui/Input';
import Resource from '../ui/Resource';
import Switch from '../ui/Switch';
import TableSkeleton from '../ui/TableSkeleton';
import { useConfirm } from '../ui/ConfirmDialog';

/** Search the tenant's available repositories and pick one to link. */
function RepositoryPicker({ linked, onPick, onCancel }) {
    const { t } = useTranslation();
    const [term, setTerm] = useState('');
    const res = useResource(
        (signal) => api.get('/integrations/git/repositories', { params: { q: term || undefined }, signal }).then(({ data }) => data.repositories),
        [term],
    );

    return (
        <div className="mt-4 rounded-lg border border-line p-4">
            <Input label={t('tms.repositories.search')} value={term} onChange={(e) => setTerm(e.target.value)} placeholder="acme/api" />
            <Resource {...res} skeleton={<TableSkeleton columns={2} />} errorTitle={t('tms.repositories.loadFailed')}>
                {(repos) => (repos.length === 0 ? (
                    <EmptyState title={t('tms.repositories.noMatches')} description={t('tms.repositories.noMatchesHint')} />
                ) : (
                    <ul className="mt-3 max-h-64 divide-y divide-line overflow-y-auto">
                        {repos.map((repo) => {
                            const already = linked.some((l) => l.full_name === repo.full_name);
                            return (
                                <li key={repo.uuid} className="flex items-center justify-between gap-3 py-2">
                                    <div className="min-w-0">
                                        <span className="block truncate text-sm font-medium text-fg">{repo.full_name}</span>
                                        <span className="text-caption text-fg-muted">{repo.private ? t('tms.repositories.private') : t('tms.repositories.public')}</span>
                                    </div>
                                    <Button size="sm" variant="secondary" disabled={already} onClick={() => onPick(repo)}>
                                        {already ? t('tms.repositories.alreadyLinked') : t('tms.repositories.link')}
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                ))}
            </Resource>
            <div className="mt-3 flex justify-end">
                <Button variant="ghost" onClick={onCancel}>{t('tms.repositories.cancel')}</Button>
            </div>
        </div>
    );
}

/** Repositories a project follows (Project Settings). Smart commits are opt-in per link. */
export default function RepositoriesPanel({ projectId, canManage }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const { can, hasModule } = useAuth();
    const [picking, setPicking] = useState(false);
    const [error, setError] = useState(null);
    const allowed = hasModule('git') && can('git.view');
    const manage = canManage && can('git.manage');
    const res = useResource(
        (signal) => (allowed
            ? api.get(`/projects/${projectId}/repositories`, { signal }).then(({ data }) => data.repositories)
            : Promise.resolve([])),
        [projectId, allowed],
    );

    if (!allowed) return null;

    async function act(fn, ok) {
        setError(null);
        try {
            await fn();
            if (ok) toast.success(ok);
            res.reload();
        } catch (err) {
            const msg = fieldErrors(err).repository_uuid || fieldErrors(err).form || t('tms.repositories.requestFailed');
            setError(msg);
        }
    }

    const link = (repo) => act(async () => { await api.post(`/projects/${projectId}/repositories`, { repository_uuid: repo.uuid }); setPicking(false); }, t('tms.repositories.linked'));
    const update = (repo, patch) => act(() => api.put(`/projects/${projectId}/repositories/${repo.id}`, patch));

    async function unlink(repo) {
        const ok = await confirm({ title: t('tms.repositories.unlinkTitle', { name: repo.full_name }), message: t('tms.repositories.unlinkMessage'), variant: 'danger' });
        if (!ok) return;
        const removeData = await confirm({ title: t('tms.repositories.removeDataTitle'), message: t('tms.repositories.removeDataMessage') });
        await act(() => api.delete(`/projects/${projectId}/repositories/${repo.id}`, { data: { remove_linked_data: removeData } }), t('tms.repositories.unlinked'));
    }

    return (
        <Card
            title={t('tms.repositories.title')}
            subtitle={t('tms.repositories.subtitle')}
            actions={manage && !picking && <Button size="sm" onClick={() => setPicking(true)}>{t('tms.repositories.linkRepository')}</Button>}
        >
            {error && <Alert>{error}</Alert>}
            <Resource {...res} skeleton={<TableSkeleton columns={3} />} errorTitle={t('tms.repositories.loadFailed')}>
                {(repos) => (
                    <>
                        {repos.length === 0 && !picking && <EmptyState title={t('tms.repositories.empty')} description={t('tms.repositories.emptyHint')} />}
                        <ul className="divide-y divide-line">
                            {repos.map((repo) => (
                                <li key={repo.id} className="py-3">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="min-w-0">
                                            <a href={repo.html_url} target="_blank" rel="noreferrer" className="text-sm font-medium text-fg hover:underline">{repo.full_name}</a>
                                            {!repo.sync_enabled && <Badge tone="warning" className="ml-2">{t('tms.repositories.paused')}</Badge>}
                                        </div>
                                        {manage && <Button size="sm" variant="danger" onClick={() => unlink(repo)}>{t('tms.repositories.unlink')}</Button>}
                                    </div>
                                    {manage && (
                                        <div className="mt-3 grid gap-3 sm:grid-cols-3">
                                            <Switch checked={repo.sync_enabled} onChange={(v) => update(repo, { sync_enabled: v })} label={t('tms.repositories.sync')} description={t('tms.repositories.syncHint')} />
                                            <Switch checked={repo.smart_commits_enabled} onChange={(v) => update(repo, { smart_commits_enabled: v })} label={t('tms.repositories.smartCommits')} description={t('tms.repositories.smartCommitsHint')} />
                                            <Switch checked={repo.smart_commit_transitions_enabled} disabled={!repo.smart_commits_enabled} onChange={(v) => update(repo, { smart_commit_transitions_enabled: v })} label={t('tms.repositories.smartTransitions')} description={t('tms.repositories.smartTransitionsHint')} />
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {picking && <RepositoryPicker linked={repos} onPick={link} onCancel={() => setPicking(false)} />}
                    </>
                )}
            </Resource>
        </Card>
    );
}
