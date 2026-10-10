import { useEffect, useState } from 'react';
import api from '../services/api';
import Spinner from '../components/ui/Spinner';
import MetricCard from '../components/ui/MetricCard';
import StatusPill from '../components/ui/StatusPill';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

const LATENCY_HOURS = [
    { hour: '00', p95: 140, p50: 65 },
    { hour: '02', p95: 185, p50: 90 },
    { hour: '04', p95: 220, p50: 120 },
    { hour: '06', p95: 245, p50: 135 },
    { hour: '08', p95: 280, p50: 155 },
    { hour: '10', p95: 175, p50: 85 },
    { hour: '12', p95: 215, p50: 110 },
    { hour: '14', p95: 235, p50: 130 },
    { hour: '16', p95: 270, p50: 150 },
    { hour: '18', p95: 165, p50: 80 },
    { hour: '20', p95: 195, p50: 95 },
    { hour: '22', p95: 225, p50: 115 },
];

const SERVICES = [
    { name: 'Identity & SSO', status: 'Operational', variant: 'healthy' },
    { name: 'Tenant routing', status: 'Operational', variant: 'healthy' },
    { name: 'Queue workers', status: 'Operational', variant: 'healthy' },
    { name: 'Email delivery', status: 'Degraded', variant: 'warning' },
    { name: 'DB connection pool', status: 'Operational', variant: 'healthy' },
];

const INCIDENTS = [
    { id: 'INC-204', title: 'Email delivery latency elevated', status: 'Monitoring', variant: 'warning', time: '16:21' },
    { id: 'OPS-881', title: 'Tenant DB migration completed', status: 'Resolved', variant: 'healthy', time: '15:42' },
    { id: 'INC-201', title: 'Nexus provisioning retry', status: 'Investigating', variant: 'warning', time: '14:08' },
];

export default function SystemAnalytics() {
    usePageTitle('Platform health');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Platform health' }]);

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/system/analytics')
            .then(({ data }) => setData(data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner size="lg" />
            </div>
        );
    }

    const provisioningFailures = data?.tenants?.provisioning_failed ?? 1;

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 29 */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Platform health
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        Service availability, databases, queues, latency and tenant provisioning status.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-[#e6f7ef] px-3 py-1.5 text-xs font-semibold text-[#1f9b69]">
                        <span className="h-2 w-2 rounded-full bg-[#1f9b69]" />
                        All systems healthy
                    </span>
                </div>
            </div>

            {/* 4 Metric Cards: Exact match to Figma Screen 29 */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Platform availability"
                    value="99.98%"
                    badge="+0.01%"
                    badgeVariant="healthy"
                    accentColor="#1f9b69"
                    progress={99}
                />
                <MetricCard
                    title="API p95 latency"
                    value="182 ms"
                    badge="-12%"
                    badgeVariant="healthy"
                    accentColor="#4b5ef5"
                    progress={65}
                />
                <MetricCard
                    title="Queue backlog"
                    value="142"
                    badge="Normal"
                    badgeVariant="healthy"
                    accentColor="#0d9488"
                    progress={42}
                />
                <MetricCard
                    title="Provisioning failures"
                    value={provisioningFailures}
                    badge="Investigate"
                    badgeVariant="danger"
                    accentColor="#d94e61"
                    progress={18}
                />
            </div>

            {/* Middle Section: Latency Histogram & Service Status */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* 24-Hour Latency Chart (2 Cols) */}
                <div className="lg:col-span-2 rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-6">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">API latency · 24 hours</h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            p50 and p95 response time in milliseconds
                        </p>
                    </div>

                    {/* Chart visualization */}
                    <div className="flex h-56 items-end justify-between gap-2 px-2 pt-4">
                        {LATENCY_HOURS.map((slot) => {
                            const p95Height = (slot.p95 / 300) * 100;
                            const p50Height = (slot.p50 / 300) * 100;

                            return (
                                <div key={slot.hour} className="flex flex-1 flex-col items-center gap-2">
                                    <div className="flex h-44 w-full items-end justify-center gap-1.5">
                                        {/* p95 Bar (Blue) */}
                                        <div
                                            style={{ height: `${p95Height}%` }}
                                            className="w-3.5 rounded-t-md bg-[#4b5ef5] transition-all duration-300 hover:brightness-110"
                                            title={`p95: ${slot.p95}ms`}
                                        />
                                        {/* p50 Bar (Teal) */}
                                        <div
                                            style={{ height: `${p50Height}%` }}
                                            className="w-3.5 rounded-t-md bg-[#0d9488] transition-all duration-300 hover:brightness-110"
                                            title={`p50: ${slot.p50}ms`}
                                        />
                                    </div>
                                    <span className="text-[11px] font-medium text-[#94a3b8]">{slot.hour}</span>
                                </div>
                            );
                        })}
                    </div>

                    {/* Chart Legend */}
                    <div className="mt-4 flex items-center justify-center gap-6 border-t border-[#f1f5f9] pt-4 dark:border-[#232b3e]">
                        <div className="flex items-center gap-2 text-xs font-medium text-[#475569] dark:text-[#cbd5e1]">
                            <span className="h-3 w-3 rounded-sm bg-[#4b5ef5]" />
                            p95 latency
                        </div>
                        <div className="flex items-center gap-2 text-xs font-medium text-[#475569] dark:text-[#cbd5e1]">
                            <span className="h-3 w-3 rounded-sm bg-[#0d9488]" />
                            p50 latency
                        </div>
                    </div>
                </div>

                {/* Service Status List (1 Col) */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-4">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Service status</h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Core dependency checks
                        </p>
                    </div>

                    <div className="space-y-4">
                        {SERVICES.map((s) => (
                            <div key={s.name} className="flex items-center justify-between py-2 border-b border-[#f1f5f9] last:border-0 dark:border-[#232b3e]">
                                <span className="text-xs font-semibold text-[#0f172a] dark:text-white">
                                    {s.name}
                                </span>
                                <StatusPill label={s.status} variant={s.variant} />
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Bottom Card: Active Incidents & Maintenance */}
            <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                <div className="pb-4">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-white">
                        Active incidents & maintenance
                    </h2>
                    <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Incident history and operational follow-up.
                    </p>
                </div>

                <div className="space-y-3">
                    {INCIDENTS.map((inc) => (
                        <div
                            key={inc.id}
                            className="flex flex-col gap-3 rounded-xl border border-[#f1f5f9] bg-[#f8fafc]/50 p-4 transition sm:flex-row sm:items-center sm:justify-between dark:border-[#232b3e] dark:bg-[#1a202c]/50"
                        >
                            <div className="flex items-center gap-3">
                                <span className="rounded-md bg-[#eef2f6] px-2 py-1 font-mono text-[11px] font-bold text-[#475569] dark:bg-[#20283e] dark:text-[#cbd5e1]">
                                    {inc.id}
                                </span>
                                <span className="text-xs font-semibold text-[#0f172a] dark:text-white">
                                    {inc.title}
                                </span>
                            </div>

                            <div className="flex items-center gap-4">
                                <StatusPill label={inc.status} variant={inc.variant} />
                                <span className="text-xs font-mono text-[#64748b]">{inc.time}</span>
                                <button
                                    type="button"
                                    className="rounded-lg border border-[#e3e7f0] bg-white px-3 py-1.5 text-xs font-semibold text-[#0f172a] hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                                >
                                    Details
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}