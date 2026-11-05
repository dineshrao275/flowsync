import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import DataTable from '../components/ui/DataTable';
import PageHeader from '../components/ui/PageHeader';
import Select from '../components/ui/Select';
import Tabs from '../components/ui/Tabs';
import Timestamp from '../components/ui/Timestamp';
import { useAuth } from '../context/AuthContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useResource } from '../hooks/useResource';
import { useConfirm } from '../components/ui/ConfirmDialog';

const TONE = { active: 'success', suspended: 'warning', revoked: 'neutral', processed: 'success', ignored: 'neutral', received: 'info', failed: 'danger' };

function HealthStrip({ health }) {
    const { t } = useTranslation();
    const stat = (label, value, tone) => (
        <div className="rounded-lg border border-line px-4 py-3">
            <p className="text-caption text-fg-muted">{label}</p>
            <p className={`text-h2 font-semibold ${tone ?? 'text-fg'}`}>{value}</p>
        </div>
    );

    return (
        <div className="mb-5 grid gap-3 sm:grid-cols-4">
            {stat(t('admin.platformGit.connectionsActive'), health.connections?.active ?? 0)}
            {stat(t('admin.platformGit.failed24h'), health.failed_deliveries_24h, health.failed_deliveries_24h ? 'text-danger-fg' : undefined)}
            {stat(t('admin.platformGit.stale'), health.stale_connections, health.stale_connections ? 'text-warning' : undefined)}
            {stat(t('admin.platformGit.lastDelivery'), health.last_delivery_at ? <Timestamp date={health.last_delivery_at} /> : '—')}
        </div>
    );
}

/** Platform staff view of Git connections and webhook deliveries. Metadata only: no repository names, branches or commit text. */
export default function PlatformIntegrations() {
    const { t } = useTranslation();
    usePageTitle(t('admin.platformGit.pageTitle'));
    const toast = useToast();
    const confirm = useConfirm();
    const { can } = useAuth();
    const setCrumbs = useSetCrumbs();
    const [tab, setTab] = useState('connections');
    const [status, setStatus] = useState('');
    const canManage = can('tenants.manage');
    const connections = useResource((signal) => api.get('/system/git/connections', { params: { status: status || undefined }, signal }).then(({ data }) => data), [status]);
    const deliveries = useResource((signal) => (tab === 'deliveries' ? api.get('/system/git/deliveries', { params: { status: status || undefined }, signal }).then(({ data }) => data) : Promise.resolve(null)), [tab, status]);

    useEffect(() => { setCrumbs([{ label: t('admin.platformGit.pageTitle') }]); }, [setCrumbs, t]);

    async function act(connection, action, confirmKey) {
        if (confirmKey && !(await confirm({ title: t(`admin.platformGit.${confirmKey}Title`, { tenant: connection.tenant.name }), message: t(`admin.platformGit.${confirmKey}Message`), variant: 'danger' }))) return;
        try {
            await api.post(`/system/git/connections/${connection.uuid}/${action}`);
            toast.success(t('admin.platformGit.done'));
            connections.reload();
        } catch (err) {
            toast.error(fieldErrors(err).form || err.response?.data?.message || t('admin.platformGit.failed'));
        }
    }

    const connectionColumns = [
        { key: 'tenant', header: t('admin.platformGit.tenant'), render: (c) => <span className="font-medium text-fg">{c.tenant.name} <span className="text-fg-muted">({c.tenant.slug})</span></span> },
        { key: 'account', header: t('admin.platformGit.account'), render: (c) => c.account_login },
        { key: 'status', header: t('admin.platformGit.status'), render: (c) => <span className="flex items-center gap-1"><Badge tone={TONE[c.status]}>{c.status}</Badge>{c.disabled_by_platform && <Badge tone="warning">{t('admin.platformGit.paused')}</Badge>}{c.stale && <Badge tone="warning">{t('admin.platformGit.staleBadge')}</Badge>}</span> },
        { key: 'repos', header: t('admin.platformGit.repositories'), render: (c) => c.repositories_count },
        { key: 'delivery', header: t('admin.platformGit.lastDelivery'), render: (c) => <Timestamp date={c.last_delivery_at} fallback="—" /> },
        { key: 'failed', header: t('admin.platformGit.failed24h'), render: (c) => c.failed_deliveries_24h },
        ...(canManage ? [{ key: 'actions', header: '', render: (c) => (
            <span className="flex justify-end gap-2">
                {c.status === 'active' && <Button size="sm" variant="ghost" onClick={() => act(c, 'sync')}>{t('admin.platformGit.sync')}</Button>}
                {c.status === 'active' && <Button size="sm" variant="ghost" onClick={() => act(c, 'disable', 'disable')}>{t('admin.platformGit.disable')}</Button>}
                {c.disabled_by_platform && <Button size="sm" variant="ghost" onClick={() => act(c, 'enable')}>{t('admin.platformGit.enable')}</Button>}
            </span>
        ) }] : []),
    ];
    const deliveryColumns = [
        { key: 'received', header: t('admin.platformGit.received'), render: (d) => <Timestamp date={d.received_at} /> },
        { key: 'event', header: t('admin.platformGit.event'), render: (d) => `${d.event}${d.action ? ` · ${d.action}` : ''}` },
        { key: 'status', header: t('admin.platformGit.status'), render: (d) => <Badge tone={TONE[d.status]}>{d.status}</Badge> },
        { key: 'tenant', header: t('admin.platformGit.tenantId'), render: (d) => d.tenant_id ?? '—' },
        { key: 'error', header: t('admin.platformGit.error'), render: (d) => <span className="text-fg-muted">{d.error ?? '—'}</span> },
    ];

    return (
        <div>
            <PageHeader title={t('admin.platformGit.title')} description={t('admin.platformGit.subtitle')} />
            {connections.data && <HealthStrip health={connections.data.health} />}
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <Tabs active={tab} onChange={(k) => { setTab(k); setStatus(''); }} tabs={[{ key: 'connections', label: t('admin.platformGit.connections') }, { key: 'deliveries', label: t('admin.platformGit.deliveries') }]} label={t('admin.platformGit.title')} />
                <Select aria-label={t('admin.platformGit.status')} value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">{t('admin.platformGit.anyStatus')}</option>
                    {(tab === 'connections' ? ['active', 'suspended', 'revoked'] : ['received', 'processed', 'ignored', 'failed']).map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
            </div>
            {tab === 'connections'
                ? <DataTable columns={connectionColumns} rows={connections.data?.connections ?? []} rowKey={(c) => c.uuid} loading={connections.loading && !connections.data} error={connections.error ? t('admin.platformGit.loadFailed') : null} onRetry={connections.reload} />
                : <DataTable columns={deliveryColumns} rows={deliveries.data?.deliveries ?? []} loading={deliveries.loading && !deliveries.data} error={deliveries.error ? t('admin.platformGit.loadFailed') : null} onRetry={deliveries.reload} />}
        </div>
    );
}
