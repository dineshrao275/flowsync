import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
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
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td, TableEmpty } from '../../components/ui/Table';
import { Tiles, HBar } from '../../components/hrms/AnalyticsWidgets';
import { formatMinutes } from '../../utils/time';

const TABS = [
    { key: 'overview', label: 'Overview', permission: 'hrms.analytics.view', endpoint: '/hrms/analytics/overview', exportDomain: null },
    { key: 'attendance', label: 'Attendance', permission: 'hrms.attendance.view', endpoint: '/hrms/analytics/attendance', exportDomain: 'attendance' },
    { key: 'leave', label: 'Leave', permission: 'hrms.leave.manage', endpoint: '/hrms/analytics/leave', exportDomain: 'leave' },
    { key: 'lifecycle', label: 'Lifecycle', permission: 'hrms.analytics.view', endpoint: '/hrms/analytics/lifecycle', exportDomain: 'lifecycle' },
    { key: 'performance', label: 'Performance', permission: 'hrms.analytics.view', endpoint: '/hrms/analytics/performance', exportDomain: 'performance' },
    { key: 'payroll', label: 'Payroll', permission: 'hrms.payroll.run', endpoint: '/hrms/analytics/payroll', exportDomain: 'payroll' },
    { key: 'documents', label: 'Documents', permission: 'hrms.documents.view', endpoint: '/hrms/analytics/documents', exportDomain: 'documents' },
    { key: 'assets', label: 'Assets', permission: 'hrms.assets.view', endpoint: '/hrms/analytics/assets', exportDomain: 'assets' },
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
    usePageTitle('Analytics');
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
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Analytics' }]);
    }, [setCrumbs]);

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
                if (!cancelled) fail('Unable to load analytics.')(err);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [cacheKey, active, filters.from, filters.to, fail]);

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
            toast.success('Export downloaded. The pull was logged.');
        } catch (err) {
            toast.error('Unable to export analytics.');
        } finally {
            setExporting(false);
        }
    }

    if (tabs.length === 0) {
        return <EmptyState title="No analytics access" message="Your role has no analytics permissions." />;
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Analytics</h2>
                    <p className="mt-0.5 text-sm text-gray-500">Workforce dashboards across every HRMS domain.</p>
                </div>
                {active.exportDomain && canExport && (
                    <Button variant="secondary" onClick={exportCsv} disabled={exporting || !data}>
                        {exporting ? 'Exporting…' : 'Export CSV'}
                    </Button>
                )}
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <Input
                    label="From"
                    type="date"
                    value={filters.from}
                    onChange={(e) => setFilters((prev) => ({ ...prev, from: e.target.value }))}
                />
                <Input
                    label="To"
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
                                    ? 'border-indigo-600 text-indigo-700'
                                    : 'border-transparent text-gray-500 hover:text-gray-800'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>
            )}

            {error && <Alert type="error">{error}</Alert>}

            {loading && !data ? (
                <Spinner />
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

function mapOf(record) {
    return Object.entries(record ?? {}).map(([name, value]) => ({ name, value: Number(value) || 0 }));
}

function OverviewTab({ payload }) {
    const headcount = payload.headcount ?? {};
    const attendance = payload.attendance ?? {};
    const leave = payload.leave ?? {};
    const lifecycle = payload.lifecycle ?? {};
    const performance = payload.performance ?? {};

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: 'Headcount', value: headcount.total },
                    { label: 'New hires (month)', value: headcount.new_hires_this_month },
                    { label: 'Exits (month)', value: headcount.exits_this_month },
                    { label: 'Attrition (3 mo)', value: headcount.attrition_3mo_percent != null ? `${headcount.attrition_3mo_percent}%` : null },
                    { label: 'Present days', value: attendance.present_days, hint: 'current window' },
                    { label: 'Absent days', value: attendance.absent_days, hint: 'current window' },
                    { label: 'Leave pending', value: leave.pending_approvals },
                    { label: 'Onboarding done', value: lifecycle.onboarding_completion_percent != null ? `${lifecycle.onboarding_completion_percent}%` : null },
                    { label: 'Reviews complete', value: performance.review_completion_percent != null ? `${performance.review_completion_percent}%` : null },
                ]}
            />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title="Headcount by department" subtitle="Active employees per team">
                    <HBar data={mapOf(headcount.by_department)} emptyHint="No departments to chart yet." />
                </Card>
                <Card title="Leave by type" subtitle="Approved days per leave type">
                    <HBar
                        data={(leave.by_type ?? []).map((row) => ({ name: row.type, value: Number(row.days) || 0 }))}
                        color="#059669"
                        emptyHint="No approved leave in this window."
                    />
                </Card>
            </div>
        </div>
    );
}

function AttendanceTab({ payload }) {
    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: 'Present days', value: payload.present_days },
                    { label: 'Absent days', value: payload.absent_days },
                    { label: 'Late days', value: payload.late_days },
                    { label: 'Leave days', value: payload.leave_days },
                    { label: 'Avg worked', value: payload.average_worked_hours != null ? `${payload.average_worked_hours}h` : null },
                    { label: 'Overtime', value: formatMinutes(payload.overtime_minutes) },
                ]}
            />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title="Most late" subtitle="Top 5 by late minutes">
                    <HBar
                        data={(payload.top_late ?? []).map((row) => ({ name: row.name, value: Number(row.minutes) || 0 }))}
                        color="#d97706"
                        format={(value) => formatMinutes(value)}
                        emptyHint="Nobody ran late in this window."
                    />
                </Card>
                <Card title="Most overtime" subtitle="Top 5 by overtime minutes">
                    <HBar
                        data={(payload.top_overtime ?? []).map((row) => ({ name: row.name, value: Number(row.minutes) || 0 }))}
                        color="#4f46e5"
                        format={(value) => formatMinutes(value)}
                        emptyHint="No overtime in this window."
                    />
                </Card>
            </div>
        </div>
    );
}

function LeaveTab({ payload }) {
    const pending = payload.pending_items ?? [];

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: 'Pending approvals', value: payload.pending_approvals },
                    { label: 'Expiry liability', value: payload.expiry_liability ?? 'Withheld', hint: payload.expiry_liability == null ? 'needs the payroll run permission' : 'estimate' },
                ]}
            />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title="Leave by type" subtitle="Approved days per leave type">
                    <HBar
                        data={(payload.by_type ?? []).map((row) => ({ name: row.type, value: Number(row.days) || 0 }))}
                        color="#059669"
                        emptyHint="No approved leave in this window."
                    />
                </Card>
                <Card title="Top consumers" subtitle="Top 5 by approved days">
                    <HBar
                        data={(payload.top_consumers ?? []).map((row) => ({ name: row.name, value: Number(row.days) || 0 }))}
                        emptyHint="No approved leave in this window."
                    />
                </Card>
            </div>
            <Card title="Pending requests" subtitle="Oldest ten awaiting a decision">
                <Table>
                    <thead>
                        <tr>
                            <Th>Employee</Th>
                            <Th>From</Th>
                            <Th>To</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {pending.length === 0 ? (
                            <TableEmpty colSpan={3}>Nothing awaiting a decision.</TableEmpty>
                        ) : (
                            pending.map((row) => (
                                <tr key={row.id}>
                                    <Td>{row.employee}</Td>
                                    <Td>{row.from_date}</Td>
                                    <Td>{row.to_date}</Td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </Table>
            </Card>
        </div>
    );
}

function LifecycleTab({ payload }) {
    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: 'Onboarding avg', value: payload.onboarding_avg_days != null ? `${payload.onboarding_avg_days} days` : null },
                    { label: 'Offboarding avg', value: payload.offboarding_avg_days != null ? `${payload.offboarding_avg_days} days` : null },
                    { label: 'Onboarding done', value: payload.onboarding_completion_percent != null ? `${payload.onboarding_completion_percent}%` : null },
                    { label: 'Offboarding done', value: payload.offboarding_completion_percent != null ? `${payload.offboarding_completion_percent}%` : null },
                    { label: 'Time to first day', value: payload.time_to_first_day_avg != null ? `${payload.time_to_first_day_avg} days` : null },
                ]}
            />
            <Card title="Checklist completion" subtitle="Share of mandatory items closed">
                <HBar
                    data={[
                        { name: 'Onboarding', value: Number(payload.onboarding_completion_percent) || 0 },
                        { name: 'Offboarding', value: Number(payload.offboarding_completion_percent) || 0 },
                    ]}
                    color="#059669"
                    format={(value) => `${value}%`}
                />
            </Card>
        </div>
    );
}

function PerformanceTab({ payload }) {
    const ratings = payload.ratings ?? null;
    const goals = Object.entries(payload.goals_by_status ?? {}).reduce((sum, [, count]) => sum + Number(count), 0);

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: 'Goals tracked', value: goals },
                    { label: 'Reviews complete', value: payload.review_completion_percent != null ? `${payload.review_completion_percent}%` : null },
                ]}
            />
            <Card title="Goals by status" subtitle="Counts only — never scores">
                <HBar data={mapOf(payload.goals_by_status)} emptyHint="No goals to chart yet." />
            </Card>
            {ratings && (
                <Card title="Manager ratings" subtitle="Non-peer cycles with a shared rating">
                    <Table>
                        <thead>
                            <tr>
                                <Th>Cycle</Th>
                                <Th>Average</Th>
                                <Th>Reviews</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {ratings.length === 0 ? (
                                <TableEmpty colSpan={3}>No shared ratings yet.</TableEmpty>
                            ) : (
                                ratings.map((row) => (
                                    <tr key={row.cycle}>
                                        <Td>{row.cycle}</Td>
                                        <Td>{row.average_manager_rating}</Td>
                                        <Td>{row.reviews}</Td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </Table>
                </Card>
            )}
        </div>
    );
}

function PayrollTab({ payload }) {
    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: 'Total gross', value: payload.total_gross },
                    { label: 'Total net', value: payload.total_net },
                    { label: 'Annual CTC', value: payload.total_annual_ctc, hint: 'trailing 365 days' },
                ]}
            />
            <Card title="Cost by department" subtitle="Annual CTC from live salary structures">
                <HBar
                    data={(payload.department_cost ?? []).map((row) => ({ name: row.department, value: Number(row.annual_ctc) || 0 }))}
                    color="#059669"
                    emptyHint="No salary structures to cost yet."
                />
            </Card>
        </div>
    );
}

function DocumentsTab({ payload }) {
    const expiring = payload.expiring ?? {};
    const windows = Object.entries(expiring);

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: 'Mandatory types', value: (payload.compliance ?? []).length },
                    ...windows.map(([window, bucket]) => ({ label: `Expiring ${window}`, value: bucket?.count })),
                ]}
            />
            <Card title="Compliance" subtitle="Share of active employees holding each mandatory type">
                <HBar
                    data={(payload.compliance ?? []).map((row) => ({ name: row.type, value: Number(row.percent) || 0 }))}
                    color="#059669"
                    format={(value) => `${value}%`}
                    emptyHint="No mandatory document types yet."
                />
            </Card>
            {windows.map(([window, bucket]) => (
                <Card key={window} title={`Expiring in ${window}`} subtitle={`${bucket?.count ?? 0} files`}>
                    <Table>
                        <thead>
                            <tr>
                                <Th>File</Th>
                                <Th>Employee</Th>
                                <Th>Expires</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {(bucket?.items ?? []).length === 0 ? (
                                <TableEmpty colSpan={3}>Nothing expiring in this window.</TableEmpty>
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
    const total = Object.values(payload.by_status ?? {}).reduce((sum, count) => sum + Number(count), 0);

    return (
        <div className="space-y-6">
            <Tiles items={[{ label: 'Assets tracked', value: total }]} />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title="Assets by status" subtitle="Register counts per lifecycle state">
                    <HBar data={mapOf(payload.by_status)} emptyHint="No assets on the register yet." />
                </Card>
                <Card title="Assigned per department" subtitle="By the holder's department">
                    <HBar
                        data={(payload.per_department ?? []).map((row) => ({ name: row.department, value: Number(row.assigned) || 0 }))}
                        color="#059669"
                        emptyHint="Nothing assigned yet."
                    />
                </Card>
            </div>
        </div>
    );
}
