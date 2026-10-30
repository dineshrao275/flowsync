import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { Table, Th, Td, TableEmpty } from '../../components/ui/Table';
import { Tiles, HBar } from '../../components/hrms/AnalyticsWidgets';
import { mapOf, OverviewTab, AttendanceTab, LeaveTab, LifecycleTab, PerformanceTab, PayrollTab } from '../../components/hrms/analytics/AnalyticsTabs';

const TABS = [
    { key: 'overview', labelKey: 'hrms.analytics.tabs.overview', permission: 'hrms.analytics.view', endpoint: '/hrms/analytics/overview', exportDomain: null },
    { key: 'attendance', labelKey: 'hrms.analytics.tabs.attendance', permission: 'hrms.attendance.view', endpoint: '/hrms/analytics/attendance', exportDomain: 'attendance' },
    { key: 'leave', labelKey: 'hrms.analytics.tabs.leave', permission: 'hrms.leave.manage', endpoint: '/hrms/analytics/leave', exportDomain: 'leave' },
    { key: 'lifecycle', labelKey: 'hrms.analytics.tabs.lifecycle', permission: 'hrms.analytics.view', endpoint: '/hrms/analytics/lifecycle', exportDomain: 'lifecycle' },
    { key: 'performance', labelKey: 'hrms.analytics.tabs.performance', permission: 'hrms.analytics.view', endpoint: '/hrms/analytics/performance', exportDomain: 'performance' },
    { key: 'payroll', labelKey: 'hrms.analytics.tabs.payroll', permission: 'hrms.payroll.run', endpoint: '/hrms/analytics/payroll', exportDomain: 'payroll' },
    { key: 'documents', labelKey: 'hrms.analytics.tabs.documents', permission: 'hrms.documents.view', endpoint: '/hrms/analytics/documents', exportDomain: 'documents' },
    { key: 'assets', labelKey: 'hrms.analytics.tabs.assets', permission: 'hrms.assets.view', endpoint: '/hrms/analytics/assets', exportDomain: 'assets' },
];

/**
 * The workforce dashboards: one stat-tile row over the overview payload,
 * plus a tab per domain with its own charts and a CSV export.
 *
 * Tabs mirror the backend gates exactly — a tab renders only when the
 * caller holds the tab route's permission, so the page never offers a
 * chart the API would 403. Exports hit the matching export domain with
 * the same filters on screen; the backend writes the access row.
 */
export default function Analytics() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.analytics.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();
    const [searchParams, setSearchParams] = useSearchParams();

    const tabs = useMemo(() => TABS.filter((tab) => can(tab.permission)), [can]);
    const canExport = can('permission:workspaces.manage');
    const requested = searchParams.get('tab');
    const activeTab = tabs.some((tab) => tab.key === requested) ? requested : (tabs[0]?.key ?? 'overview');
    const active = TABS.find((tab) => tab.key === activeTab) ?? TABS[0];

    const [filters, setFilters] = useState({ from: '', to: '' });
    const [cache, setCache] = useState({});
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [exporting, setExporting] = useState(false);
    const cacheRef = useRef(cache);
    cacheRef.current = cache;

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: t('hrms.analytics.pageTitle') }]);
    }, [setCrumbs, t]);

    const cacheKey = `${active.key}|${filters.from}|${filters.to}`;
    const data = cache[cacheKey] ?? null;

    const fail = useCallback(
        (message) => (err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }
            setError(message);
        },
        [navigate],
    );

    useEffect(() => {
        if (cacheRef.current[cacheKey] || !active.endpoint) return;

        let cancelled = false;
        setLoading(true);
        setError(null);

        api.get(active.endpoint, { params: { ...(filters.from ? { from: filters.from } : {}), ...(filters.to ? { to: filters.to } : {}) } })
            .then((response) => {
                if (!cancelled) setCache((prev) => ({ ...prev, [cacheKey]: response.data }));
            })
            .catch((err) => {
                if (!cancelled) fail(t('hrms.analytics.loadFailed'))(err);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [cacheKey, active, filters.from, filters.to, fail, t]);

    function changeTab(key) {
        setSearchParams(key === tabs[0]?.key ? {} : { tab: key });
    }

    async function exportCsv() {
        if (!active.exportDomain) return;

        setExporting(true);

        try {
            const response = await api.get('/hrms/analytics/export', {
                params: {
                    domain: active.exportDomain,
                    ...(filters.from ? { from: filters.from } : {}),
                    ...(filters.to ? { to: filters.to } : {}),
                },
                responseType: 'blob',
            });

            const url = window.URL.createObjectURL(new Blob([response.data], { type: 'text/csv' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = `hrms-analytics-${active.exportDomain}-${filters.from || 'all'}_${filters.to || 'all'}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
            toast.success(t('hrms.analytics.exportDownloaded'));
        } catch {
            toast.error(t('hrms.analytics.exportFailed'));
        } finally {
            setExporting(false);
        }
    }

    if (tabs.length === 0) {
        return <EmptyState title={t('hrms.analytics.noAccessTitle')} message={t('hrms.analytics.noAccessMessage')} />;
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.analytics.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">{t('hrms.analytics.subtitle')}</p>
                </div>
                {active.exportDomain && canExport && (
                    <Button variant="secondary" onClick={exportCsv} disabled={exporting || !data}>
                        {exporting ? t('hrms.analytics.exporting') : t('hrms.analytics.exportCsv')}
                    </Button>
                )}
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <Input
                    label={t('hrms.analytics.from')}
                    type="date"
                    value={filters.from}
                    onChange={(e) => setFilters((prev) => ({ ...prev, from: e.target.value }))}
                />
                <Input
                    label={t('hrms.analytics.to')}
                    type="date"
                    value={filters.to}
                    onChange={(e) => setFilters((prev) => ({ ...prev, to: e.target.value }))}
                />
            </div>

            {tabs.length > 1 && (
                <nav className="flex flex-wrap gap-1 border-b border-gray-200">
                    {tabs.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => changeTab(tab.key)}
                            className={`border-b-2 px-3 py-2 text-sm font-medium ${
                                activeTab === tab.key
                                    ? 'border-[var(--accent)] text-[var(--accent-soft-text)]'
                                    : 'border-transparent text-gray-500 hover:text-gray-800'
                            }`}
                        >
                            {t(tab.labelKey)}
                        </button>
                    ))}
                </nav>
            )}

            {error && <Alert type="error">{error}</Alert>}

            {loading && !data ? (
                <ContentSkeleton size="sm" />
            ) : (
                data && (
                    <>
                        {activeTab === 'overview' && <OverviewTab payload={data} />}
                        {activeTab === 'attendance' && <AttendanceTab payload={data} />}
                        {activeTab === 'leave' && <LeaveTab payload={data} />}
                        {activeTab === 'lifecycle' && <LifecycleTab payload={data} />}
                        {activeTab === 'performance' && <PerformanceTab payload={data} />}
                        {activeTab === 'payroll' && <PayrollTab payload={data} />}
                        {activeTab === 'documents' && <DocumentsTab payload={data} />}
                        {activeTab === 'assets' && <AssetsTab payload={data} />}
                    </>
                )
            )}
        </div>
    );
}

function DocumentsTab({ payload }) {
    const { t } = useTranslation();
    const expiring = payload.expiring ?? {};
    const windows = Object.entries(expiring);

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: t('hrms.analytics.tiles.mandatoryTypes'), value: (payload.compliance ?? []).length },
                    ...windows.map(([window, bucket]) => ({ label: t('hrms.analytics.expiring', { window }), value: bucket?.count })),
                ]}
            />
            <Card title={t('hrms.analytics.cards.compliance')} subtitle={t('hrms.analytics.cards.shareActive')}>
                <HBar
                    data={(payload.compliance ?? []).map((row) => ({ name: row.type, value: Number(row.percent) || 0 }))}
                    color="#059669"
                    format={(value) => `${value}%`}
                    emptyHint={t('hrms.analytics.empties.noMandatoryDocs')}
                />
            </Card>
            {windows.map(([window, bucket]) => (
                <Card key={window} title={t('hrms.analytics.expiringIn', { window })} subtitle={t('hrms.analytics.files', { count: bucket?.count ?? 0 })}>
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.analytics.table.file')}</Th>
                                <Th>{t('hrms.analytics.table.employee')}</Th>
                                <Th>{t('hrms.analytics.table.expires')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {(bucket?.items ?? []).length === 0 ? (
                                <TableEmpty colSpan={3}>{t('hrms.analytics.empties.nothingExpiring')}</TableEmpty>
                            ) : (
                                (bucket.items ?? []).map((row) => (
                                    <tr key={row.id}>
                                        <Td>{row.title}</Td>
                                        <Td>{row.employee}</Td>
                                        <Td>{row.expires_at}</Td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </Table>
                </Card>
            ))}
        </div>
    );
}

function AssetsTab({ payload }) {
    const { t } = useTranslation();
    const total = Object.values(payload.by_status ?? {}).reduce((sum, count) => sum + Number(count), 0);

    return (
        <div className="space-y-6">
            <Tiles items={[{ label: t('hrms.analytics.tiles.assetsTracked'), value: total }]} />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title={t('hrms.analytics.cards.assetsByStatus')} subtitle={t('hrms.analytics.cards.registerCounts')}>
                    <HBar data={mapOf(payload.by_status)} emptyHint={t('hrms.analytics.empties.noAssets')} />
                </Card>
                <Card title={t('hrms.analytics.cards.assignedPerDept')} subtitle={t('hrms.analytics.cards.byHolderDept')}>
                    <HBar
                        data={(payload.per_department ?? []).map((row) => ({ name: row.department, value: Number(row.assigned) || 0 }))}
                        color="#059669"
                        emptyHint={t('hrms.analytics.empties.nothingAssigned')}
                    />
                </Card>
            </div>
        </div>
    );
}
