import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Alert from '../components/ui/Alert';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Card from '../components/ui/Card';
import EmptyState from '../components/ui/EmptyState';
import PageHeader from '../components/ui/PageHeader';
import Resource from '../components/ui/Resource';
import Timestamp from '../components/ui/Timestamp';
import { useConfirm } from '../components/ui/ConfirmDialog';
import GitIdentities from '../components/settings/GitIdentities';
import GitNextSteps from '../components/settings/GitNextSteps';
import { useAuth } from '../context/AuthContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useResource } from '../hooks/useResource';

const STATUS_TONE = { active: 'success', suspended: 'warning', revoked: 'neutral' };

/** One connected GitHub account (App installation) with its repository count and a disconnect action. */
function ConnectionCard({ connection, canManage, onDisconnect, onSync }) {
    const { t } = useTranslation();

    return (
        <section aria-label={t('admin.git.cardAria', { account: connection.account_login })}>
        <Card dense>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="truncate text-h3 font-semibold text-fg">{connection.account_login}</h3>
                    <p className="mt-1 text-caption text-fg-muted">{connection.account_type}</p>
                </div>
                <Badge tone={STATUS_TONE[connection.status] ?? 'neutral'}>{t(`admin.git.status.${connection.status}`)}</Badge>
            </div>
            <dl className="mt-4 grid gap-3 text-body sm:grid-cols-2">
                <div>
                    <dt className="text-caption text-fg-muted">{t('admin.git.repositories')}</dt>
                    <dd className="text-fg">{t('admin.git.repoCount', { count: connection.repositories_count })}</dd>
                </div>
                <div>
                    <dt className="text-caption text-fg-muted">{t('admin.git.lastSynced')}</dt>
                    <dd className="text-fg"><Timestamp date={connection.last_synced_at} fallback={t('admin.git.neverSynced')} /></dd>
                </div>
                <div>
                    <dt className="text-caption text-fg-muted">{t('admin.git.lastDelivery')}</dt>
                    <dd className="text-fg"><Timestamp date={connection.last_delivery_at} fallback={t('admin.git.noDeliveries')} /></dd>
                </div>
                <div>
                    <dt className="text-caption text-fg-muted">{t('admin.git.connectedBy')}</dt>
                    <dd className="truncate text-fg">{connection.connected_by_name ?? '—'}</dd>
                </div>
            </dl>
            {connection.sync_error && (
                <div className="mt-3"><Alert type="error">{t('admin.git.syncError', { error: connection.sync_error })}</Alert></div>
            )}
            {connection.failed_deliveries_24h > 0 && (
                <div className="mt-3"><Alert type="warning">{t('admin.git.failedDeliveries', { count: connection.failed_deliveries_24h })}</Alert></div>
            )}
            {canManage && (
                <div className="mt-4 flex justify-end gap-2">
                    {connection.status === 'active' && (
                        <Button variant="secondary" onClick={() => onSync(connection)}>{t('admin.git.syncNow')}</Button>
                    )}
                    <Button variant="secondary" onClick={() => onDisconnect(connection)}>{t('admin.git.disconnect')}</Button>
                </div>
            )}
        </Card>
        </section>
    );
}

/** Tenant admin page for the GitHub App connection. Repositories are linked per project, not here. */
export default function Integrations() {
    const { t } = useTranslation();
    usePageTitle(t('admin.git.pageTitle'));
    const toast = useToast();
    const confirm = useConfirm();
    const { can } = useAuth();
    const setCrumbs = useSetCrumbs();
    const [params, setParams] = useSearchParams();
    const [redirecting, setRedirecting] = useState(false);
    const [flash] = useState(() => ({ connected: params.get('git_connected') === '1', error: params.get('git_error') }));
    const res = useResource((signal) => api.get('/integrations/git/connections', { signal }).then(({ data }) => data), []);
    const canManage = can('git.manage');

    useEffect(() => { setCrumbs([{ label: t('admin.git.pageTitle') }]); }, [setCrumbs, t]);

    // The setup redirect reports its result in the query string; show it once, then clean the URL.
    useEffect(() => {
        if (params.has('git_connected') || params.has('git_error')) {
            setParams({}, { replace: true });
        }
    }, [params, setParams]);

    async function install() {
        setRedirecting(true);
        try {
            const { data } = await api.get('/integrations/git/github/install');
            window.location.assign(data.url);
        } catch (err) {
            setRedirecting(false);
            toast.error(fieldErrors(err).form || err.response?.data?.message || t('admin.git.requestFailed'));
        }
    }

    async function sync(connection) {
        try {
            await api.post(`/integrations/git/connections/${connection.uuid}/sync`);
            toast.success(t('admin.git.syncStarted'));
        } catch (err) {
            toast.error(fieldErrors(err).form || err.response?.data?.message || t('admin.git.requestFailed'));
        }
    }

    async function disconnect(connection) {
        const ok = await confirm({
            title: t('admin.git.disconnectTitle', { account: connection.account_login }),
            message: t('admin.git.disconnectMessage'),
            variant: 'danger',
        });
        if (!ok) return;
        try {
            await api.delete(`/integrations/git/connections/${connection.uuid}`);
            toast.success(t('admin.git.disconnected'));
            res.reload();
        } catch (err) {
            toast.error(fieldErrors(err).form || err.response?.data?.message || t('admin.git.requestFailed'));
        }
    }

    return (
        <div>
            <PageHeader
                title={t('admin.git.title')}
                description={t('admin.git.subtitle')}
                actions={canManage && res.data?.enabled && (
                    <Button onClick={install} disabled={redirecting}>
                        {redirecting ? t('admin.git.installing') : res.data?.connections?.length ? t('admin.git.reconnect') : t('admin.git.install')}
                    </Button>
                )}
            />
            <div className="mb-4 space-y-3">
                {flash.connected && <Alert type="success">{t('admin.git.connectedFlash')}</Alert>}
                {flash.error && <Alert type="error">{flash.error}</Alert>}
            </div>
            <Resource {...res}>
                {(data) => {
                    if (!data.enabled) return <Alert type="info">{t('admin.git.notEnabled')}</Alert>;
                    if (data.connections.length === 0) {
                        return <EmptyState title={t('admin.git.empty')} description={t('admin.git.emptyHint')} />;
                    }

                    return (
                        <div className="space-y-6">
                            <div className="grid gap-4 lg:grid-cols-2">
                                {data.connections.map((c) => (
                                    <ConnectionCard key={c.uuid} connection={c} canManage={canManage} onDisconnect={disconnect} onSync={sync} />
                                ))}
                            </div>
                            <GitNextSteps canManage={canManage} />
                            {canManage && <div id="git-identities"><GitIdentities /></div>}
                        </div>
                    );
                }}
            </Resource>
        </div>
    );
}
