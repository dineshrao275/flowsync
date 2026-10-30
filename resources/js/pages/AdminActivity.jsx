import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Resource from '../components/ui/Resource';
import { useResource } from '../hooks/useResource';
import { Table, Th, Td } from '../components/ui/Table';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

function Stat({ label, value }) {
    return (
        <div className="rounded-lg border border-gray-100 px-4 py-3">
            <p className="text-2xl font-bold text-gray-900">{value}</p>
            <p className="text-xs text-gray-500">{label}</p>
        </div>
    );
}

/** Platform adoption: who is really using the product, with dormant tenants first. */
export default function AdminActivity() {
    const { t } = useTranslation();
    usePageTitle(t('admin.activity.title'));
    useSetCrumbs([{ label: t('admin.activity.title') }]);
    const res = useResource((signal) => api.get('/system/tenant-activity', { signal }).then(({ data }) => data), []);

    return (
        <Resource {...res} skeleton={<ContentSkeleton size="lg" />} errorTitle={t('admin.activity.loadFailed')}>
            {(data) => <ActivityBody data={data} />}
        </Resource>
    );
}

function ActivityBody({ data }) {
    const { t } = useTranslation();
    const dash = (v) => (v ?? '—');

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('admin.activity.title')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('admin.activity.subtitle')}</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-4">
                <Stat label={t('admin.activity.tenants')} value={data.summary.tenants} />
                <Stat label={t('admin.activity.withData')} value={data.summary.with_data} />
                <Stat label={t('admin.activity.dormant')} value={data.summary.dormant} />
                <Stat label={t('admin.activity.activeUsers')} value={data.summary.active_users_30d} />
            </div>
            <Card>
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('admin.activity.tenant')}</Th>
                            <Th>{t('admin.activity.users')}</Th>
                            <Th>{t('admin.activity.active7')}</Th>
                            <Th>{t('admin.activity.active30')}</Th>
                            <Th>{t('admin.activity.events30')}</Th>
                            <Th>{t('admin.activity.state')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.tenants.map((tn) => (
                            <tr key={tn.id}>
                                <Td>{tn.name} <span className="text-xs text-gray-400">{tn.slug}</span></Td>
                                <Td>{dash(tn.users)}</Td>
                                <Td>{dash(tn.active_7d)}</Td>
                                <Td>{dash(tn.active_30d)}</Td>
                                <Td>{dash(tn.activity_30d)}</Td>
                                <Td>{tn.dormant ? <Badge tone="warning">{t('admin.activity.dormantBadge')}</Badge> : tn.as_of ? <Badge tone="success">{t('admin.activity.ok')}</Badge> : <Badge tone="neutral">{t('admin.activity.noData')}</Badge>}</Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </Card>
        </div>
    );
}
