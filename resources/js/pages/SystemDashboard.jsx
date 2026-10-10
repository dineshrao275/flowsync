import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import MetricCard from '../components/ui/MetricCard';
import StatusPill from '../components/ui/StatusPill';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

/**
 * 03 — Super Admin Overview
 * Exact pixel-perfect implementation of `figma/web-design/03 — Super Admin Overview.png`
 */
export default function SystemDashboard() {
    usePageTitle('Platform overview');
    useSetCrumbs([{ label: 'Platform overview' }]);
    const navigate = useNavigate();

    const [data, setData] = useState(null);

    useEffect(() => {
        api.get('/system/analytics')
            .then(({ data }) => setData(data))
            .catch(() => {});
    }, []);

    const activeCount = data?.tenants?.by_status?.find((s) => s.status === 'active')?.count || 142;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div>
                <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                    Platform overview
                </h1>
                <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                    Tenant health, recurring revenue and service status across FlowSync.
                </p>
            </div>

            {/* Top 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Annual recurring revenue"
                    value="₹2.4Cr"
                    badge="+18%"
                    badgeVariant="success"
                    accentColor="#4b5ef5"
                    progress={80}
                />
                <MetricCard
                    title="Active tenants"
                    value={activeCount}
                    badge="+9%"
                    badgeVariant="success"
                    accentColor="#14b8a6"
                    progress={72}
                    onClick={() => navigate('/tenants')}
                />
                <MetricCard
                    title="Provisioned databases"
                    value="158"
                    badge="+12%"
                    badgeVariant="success"
                    accentColor="#8257e5"
                    progress={85}
                    onClick={() => navigate('/tenants')}
                />
                <MetricCard
                    title="System health"
                    value="99.98%"
                    badge="Healthy"
                    badgeVariant="healthy"
                    accentColor="#1f9b69"
                    progress={99}
                    onClick={() => navigate('/admin/analytics')}
                />
            </div>

            {/* Middle Row: Platform Growth Bar Chart & Service Health */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Platform Growth Bar Chart */}
                <div className="lg:col-span-2 rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                        Platform growth & API performance
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Monthly overview · revenue growth + latency
                    </p>

                    <div className="mt-6 flex h-52 items-end justify-between gap-3 px-4 pt-4">
                        {[
                            { month: 'Jan', height: '45%' },
                            { month: 'Feb', height: '62%' },
                            { month: 'Mar', height: '78%' },
                            { month: 'Apr', height: '94%' },
                            { month: 'May', height: '52%' },
                            { month: 'Jun', height: '68%' },
                            { month: 'Jul', height: '84%' },
                            { month: 'Aug', height: '96%' },
                            { month: 'Sep', height: '58%' },
                            { month: 'Oct', height: '74%' },
                        ].map((bar) => (
                            <div key={bar.month} className="flex flex-1 flex-col items-center gap-2">
                                <div className="w-full flex-1 flex items-end justify-center">
                                    <div
                                        className="w-full max-w-[28px] rounded-t-lg bg-[#4b5ef5] transition-all hover:bg-[#3d50e8]"
                                        style={{ height: bar.height }}
                                    />
                                </div>
                                <span className="text-[11px] font-medium text-[#64748b] dark:text-[#94a3b8]">
                                    {bar.month}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Service Health */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                        Service health
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Core services status
                    </p>

                    <div className="mt-5 space-y-4">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-[#0f172a] dark:text-[#f8fafc]">
                                Tenant provisioning
                            </span>
                            <StatusPill label="Operational" variant="success" />
                        </div>

                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-[#0f172a] dark:text-[#f8fafc]">
                                Queue processing
                            </span>
                            <StatusPill label="Operational" variant="success" />
                        </div>

                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-[#0f172a] dark:text-[#f8fafc]">
                                Database pool
                            </span>
                            <StatusPill label="Healthy" variant="healthy" />
                        </div>

                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-[#0f172a] dark:text-[#f8fafc]">
                                Notifications
                            </span>
                            <StatusPill label="Operational" variant="success" />
                        </div>
                    </div>
                </div>
            </div>

            {/* Bottom Row: Tenant Overview */}
            <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                    Tenant overview
                </h2>

                <div className="mt-5 divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                    <div className="flex items-center justify-between py-4 first:pt-0">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#4b5ef5] text-xs font-bold text-white">
                                AC
                            </div>
                            <div>
                                <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                    Acme Corp
                                </div>
                                <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                    HRMS + TMS · 850 seats
                                </div>
                            </div>
                        </div>
                        <StatusPill label="Healthy" variant="healthy" />
                    </div>

                    <div className="flex items-center justify-between py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#14b8a6] text-xs font-bold text-white">
                                IT
                            </div>
                            <div>
                                <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                    InnovateTech
                                </div>
                                <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                    HRMS · 620 seats
                                </div>
                            </div>
                        </div>
                        <StatusPill label="Healthy" variant="healthy" />
                    </div>

                    <div className="flex items-center justify-between py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#8257e5] text-xs font-bold text-white">
                                GB
                            </div>
                            <div>
                                <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                    Globex Inc.
                                </div>
                                <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                    TMS · 430 seats
                                </div>
                            </div>
                        </div>
                        <StatusPill label="Healthy" variant="healthy" />
                    </div>

                    <div className="flex items-center justify-between py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#da972e] text-xs font-bold text-white">
                                NS
                            </div>
                            <div>
                                <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                    Nexus Solutions
                                </div>
                                <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                    TMS · 290 seats
                                </div>
                            </div>
                        </div>
                        <StatusPill label="Review" variant="warning" />
                    </div>
                </div>
            </div>
        </div>
    );
}