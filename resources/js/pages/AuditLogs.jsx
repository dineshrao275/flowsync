import { useCallback, useEffect, useState } from 'react';
import ListSavedFilters from '../components/ui/ListSavedFilters';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Button from '../components/ui/Button';
import Pagination from '../components/ui/Pagination';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { useToast } from '../context/ToastContext';
import Timestamp from '../components/ui/Timestamp';

const TYPE_LABEL = { all: 'typeAll', audit: 'typeAudit', impersonation: 'typeImpersonation' };

function describe(t, action, data) {
    switch (action) {
        case 'impersonation.started':
            return t('admin.auditLogs.impStarted');
        case 'impersonation.ended':
            return t('admin.auditLogs.impEnded');
        case 'platform.settings_updated':
            return t('admin.auditLogs.settingsUpdated', { keys: (data?.keys || []).join(', ') });
        case 'system.user_created':
            return t('admin.auditLogs.userCreated', { name: data?.after?.name });
        case 'plan.module_toggled':
            return data?.enabled ? t('admin.auditLogs.moduleEnabled', { module: data?.module }) : t('admin.auditLogs.moduleDisabled', { module: data?.module });
        case 'tenant.status_changed':
            return t('admin.auditLogs.tenantStatusChanged', { to: data?.to });
        case 'tenant.deleted':
            return t('admin.auditLogs.tenantDeleted');
        case 'tenant.restored':
            return t('admin.auditLogs.tenantRestored');
        case 'auth.login':
            return data?.via === 'registration' ? t('admin.auditLogs.signedInRegistration') : t('admin.auditLogs.signedIn');
        case 'auth.login_failed':
            return t('admin.auditLogs.loginFailed');
        case 'auth.logout':
            return t('admin.auditLogs.signedOut');
        case 'audit.exported':
            return t('admin.auditLogs.exported', { rows: data?.rows ?? 0 });
        default:
            return action;
    }
}

export default function AuditLogs() {
    const { t } = useTranslation();
    usePageTitle(t('admin.auditLogs.pageTitle'));
    useSetCrumbs([{ label: t('admin.platform.breadcrumb'), to: '/admin' }, { label: t('admin.auditLogs.crumb') }]);
    const [items, setItems] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [type, setType] = useState('all');
    const [q, setQ] = useState('');
    const [loading, setLoading] = useState(true);
    const toast = useToast();

    async function verifyChain() {
        try {
            const { data } = await api.get('/system/audit-logs/verify');
            if (data.ok) toast.success(t('admin.auditLogs.verifyOk', { checked: data.checked, tombstones: data.tombstones }));
            else toast.error(t('admin.auditLogs.verifyBroken', { id: data.break.id, reason: data.break.reason }));
        } catch (e) {
            toast.error(t('admin.auditLogs.verifyFailed'));
        }
    }

    const fetchLogs = useCallback((page = 1) => {
        setLoading(true);
        api.get('/system/audit-logs', { params: { type, q: q || undefined, page } })
            .then(({ data }) => {
                setItems(data.items);
                setPagination(data.pagination);
            })
            .finally(() => setLoading(false));
    }, [type, q]);

    useEffect(() => fetchLogs(), [fetchLogs]);

    function search(e) {
        e.preventDefault();
        fetchLogs(1);
    }

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">{t('admin.auditLogs.title')}</h1>
                <p className="text-sm text-gray-500">{t('admin.auditLogs.subtitle')}</p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <div className="flex overflow-hidden rounded-lg border border-gray-200">
                    {Object.entries(TYPE_LABEL).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setType(key)}
                            className={`px-3 py-2 text-sm font-medium ${
                                type === key ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'bg-white text-gray-600 hover:bg-gray-50'
                            }`}
                        >
                            {t(`admin.auditLogs.${label}`)}
                        </button>
                    ))}
                </div>
                <form onSubmit={search} className="flex gap-2">
                    <input
                        type="search"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('admin.auditLogs.filterPlaceholder')}
                        className="w-56 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-[var(--accent)]"
                    />
                    <Button type="submit">{t('admin.auditLogs.filter')}</Button>
                </form>
                <ListSavedFilters
                    page="platform-audit-logs"
                    scope="system"
                    value={{ type: type === 'all' ? '' : type, q }}
                    onApply={(saved) => {
                        setType(saved.type || 'all');
                        setQ(saved.q || '');
                    }}
                />
                <Button type="button" variant="secondary" className="ml-auto" onClick={verifyChain}>{t('admin.auditLogs.verifyChain')}</Button>
                <a
                    href={`/api/system/audit-logs/export?${new URLSearchParams({
                        type,
                        ...(q ? { q } : {}),
                    }).toString()}`}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                >
                    {t('admin.auditLogs.exportCsv')}
                </a>
            </div>

            {loading ? (
                <ContentSkeleton size="lg" />
            ) : (
                <Table>
                    <thead>
                        <tr>
                            <Th align="right">{t('admin.auditLogs.thWhen')}</Th>
                            <Th>{t('admin.auditLogs.thActor')}</Th>
                            <Th>{t('admin.auditLogs.thAction')}</Th>
                            <Th>{t('admin.auditLogs.thTarget')}</Th>
                            <Th>{t('admin.auditLogs.thTenant')}</Th>
                            <Th align="right">{t('admin.auditLogs.thIp')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.length === 0 ? (
                            <TableEmpty colSpan={6}>{t('admin.auditLogs.empty')}</TableEmpty>
                        ) : (
                            items.map((item, index) => {
                                const targetId = item.subject_id ?? item.data?.impersonated_user_id ?? null;

                                return (
                                    <tr
                                        key={item.id}
                                        className="animate-fade-in transition-colors duration-150 hover:bg-gray-50/60"
                                        style={{ animationDelay: `${index * 30}ms` }}
                                    >
                                        <Td align="right" className="whitespace-nowrap text-xs text-gray-400">
                                            <Timestamp date={item.created_at} />
                                        </Td>
                                        <Td>
                                            <span className="block truncate font-medium text-gray-800">
                                                {item.actor?.name || t('admin.auditLogs.system')}
                                            </span>
                                            {item.actor?.email && (
                                                <span className="block truncate text-xs text-gray-400">{item.actor.email}</span>
                                            )}
                                        </Td>
                                        <Td>
                                            <Badge>{item.type === 'impersonation' ? t('admin.auditLogs.impersonationBadge') : t('admin.auditLogs.eventBadge')}</Badge>
                                            <span className="mt-0.5 block truncate font-medium text-gray-800">
                                                {describe(t, item.action, item.data)}
                                            </span>
                                        </Td>
                                        <Td className="whitespace-nowrap text-gray-600">
                                            {item.subject_type || targetId ? (
                                                <span>
                                                    {item.subject_type ?? '—'}
                                                    {targetId ? <span className="tabular-nums"> #{targetId}</span> : null}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {item.tenant?.name ?? <span className="text-gray-400">—</span>}
                                        </Td>
                                        <Td align="right" className="whitespace-nowrap text-xs text-gray-400">
                                            {item.ip_address ?? '—'}
                                        </Td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </Table>
            )}

            {pagination && (
                <Pagination
                    placement="sides"
                    page={pagination.current_page}
                    pages={pagination.last_page}
                    onChange={fetchLogs}
                />
            )}
        </div>
    );
}