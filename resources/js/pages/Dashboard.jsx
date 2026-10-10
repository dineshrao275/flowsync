import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import MetricCard from '../components/ui/MetricCard';
import StatusPill from '../components/ui/StatusPill';
import api from '../services/api';

/**
 * 02 — TMS Project Overview
 * Exact pixel-perfect implementation of `figma/web-design/02 — TMS Project Overview.png`
 */
export default function Dashboard() {
    usePageTitle('Project Workspace');
    useSetCrumbs([{ label: 'Project workspace' }]);
    const navigate = useNavigate();

    const [stats, setStats] = useState({
        active_projects: 24,
        open_issues: 186,
        in_progress: 58,
        on_time_delivery: '92%',
    });

    useEffect(() => {
        api.get('/dashboard')
            .then(({ data }) => {
                if (data?.counts) {
                    setStats((prev) => ({
                        ...prev,
                        open_issues: data.counts.my_open || 186,
                    }));
                }
            })
            .catch(() => {});
    }, []);

    return (
        <div className="space-y-6">
            {/* Header */}
            <div>
                <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                    Project workspace
                </h1>
                <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                    Projects, priorities and delivery health — connected to your people directory.
                </p>
            </div>

            {/* Top 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Active projects"
                    value={stats.active_projects}
                    badge="+4%"
                    badgeVariant="success"
                    accentColor="#8257e5"
                    progress={65}
                    onClick={() => navigate('/projects')}
                />
                <MetricCard
                    title="Open issues"
                    value={stats.open_issues}
                    badge="-8%"
                    badgeVariant="success"
                    accentColor="#4b5ef5"
                    progress={72}
                    onClick={() => navigate('/projects')}
                />
                <MetricCard
                    title="In progress"
                    value={stats.in_progress}
                    badge="+11%"
                    badgeVariant="success"
                    accentColor="#14b8a6"
                    progress={58}
                    onClick={() => navigate('/projects')}
                />
                <MetricCard
                    title="On-time delivery"
                    value={stats.on_time_delivery}
                    badge="+6%"
                    badgeVariant="success"
                    accentColor="#1f9b69"
                    progress={92}
                />
            </div>

            {/* Middle Row: Project Health & My Work */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Project Health */}
                <div className="lg:col-span-2 rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                        Project health
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Delivery confidence across active workspaces
                    </p>

                    <div className="mt-6 space-y-6">
                        {/* Q4 Roadmap */}
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span className="w-40 text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                Q4 Roadmap
                            </span>
                            <div className="flex flex-1 items-center gap-4">
                                <div className="h-2 flex-1 overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                    <div className="h-full rounded-full bg-[#1f9b69]" style={{ width: '92%' }} />
                                </div>
                                <span className="w-10 text-right text-xs font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                    92%
                                </span>
                                <StatusPill label="On track" variant="success" />
                            </div>
                        </div>

                        {/* Mobile App */}
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span className="w-40 text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                Mobile App
                            </span>
                            <div className="flex flex-1 items-center gap-4">
                                <div className="h-2 flex-1 overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                    <div className="h-full rounded-full bg-[#4b5ef5]" style={{ width: '78%' }} />
                                </div>
                                <span className="w-10 text-right text-xs font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                    78%
                                </span>
                                <StatusPill label="At risk" variant="warning" />
                            </div>
                        </div>

                        {/* Platform Reliability */}
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span className="w-40 text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                Platform Reliability
                            </span>
                            <div className="flex flex-1 items-center gap-4">
                                <div className="h-2 flex-1 overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                    <div className="h-full rounded-full bg-[#da972e]" style={{ width: '64%' }} />
                                </div>
                                <span className="w-10 text-right text-xs font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                    64%
                                </span>
                                <StatusPill label="At risk" variant="warning" />
                            </div>
                        </div>
                    </div>
                </div>

                {/* My Work */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                        My work
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Priorities for today
                    </p>

                    <div className="mt-5 space-y-4">
                        <div className="flex items-center justify-between text-xs">
                            <span className="flex items-center gap-2">
                                <span className="h-2 w-2 rounded-full bg-[#d94e61]" />
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Due today</span>
                            </span>
                            <span className="text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">4</span>
                        </div>

                        <div className="flex items-center justify-between text-xs">
                            <span className="flex items-center gap-2">
                                <span className="h-2 w-2 rounded-full bg-[#4b5ef5]" />
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">In progress</span>
                            </span>
                            <span className="text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">7</span>
                        </div>

                        <div className="flex items-center justify-between text-xs">
                            <span className="flex items-center gap-2">
                                <span className="h-2 w-2 rounded-full bg-[#8257e5]" />
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Awaiting review</span>
                            </span>
                            <span className="text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">3</span>
                        </div>

                        <div className="flex items-center justify-between text-xs">
                            <span className="flex items-center gap-2">
                                <span className="h-2 w-2 rounded-full bg-[#da972e]" />
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Blocked</span>
                            </span>
                            <span className="text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">1</span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Bottom Row: Recent Projects & Team Capacity */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Recent Projects */}
                <div className="lg:col-span-2 rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                        Recent projects
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Active workspaces and delivery milestones
                    </p>

                    <div className="mt-5 divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                        <div
                            onClick={() => navigate('/projects')}
                            className="flex cursor-pointer items-center justify-between py-3.5 first:pt-0 hover:opacity-90"
                        >
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#4b5ef5] text-xs font-bold text-white">
                                    AR
                                </div>
                                <div>
                                    <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                        Q4 Roadmap
                                    </div>
                                    <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                        42 open issues
                                    </div>
                                </div>
                            </div>
                            <StatusPill label="On track" variant="success" />
                        </div>

                        <div
                            onClick={() => navigate('/projects')}
                            className="flex cursor-pointer items-center justify-between py-3.5 hover:opacity-90"
                        >
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#14b8a6] text-xs font-bold text-white">
                                    ER
                                </div>
                                <div>
                                    <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                        Mobile App
                                    </div>
                                    <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                        28 open issues
                                    </div>
                                </div>
                            </div>
                            <StatusPill label="In progress" variant="progress" />
                        </div>

                        <div
                            onClick={() => navigate('/projects')}
                            className="flex cursor-pointer items-center justify-between py-3.5 hover:opacity-90"
                        >
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#8257e5] text-xs font-bold text-white">
                                    MC
                                </div>
                                <div>
                                    <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                        Platform Reliability
                                    </div>
                                    <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                        16 open issues
                                    </div>
                                </div>
                            </div>
                            <StatusPill label="At risk" variant="danger" />
                        </div>
                    </div>
                </div>

                {/* Team Capacity */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                        Team capacity
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Availability synchronized from HRMS
                    </p>

                    <div className="mt-5 space-y-4">
                        <div>
                            <div className="flex justify-between text-xs mb-1.5">
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Elena Rostova</span>
                                <span className="font-semibold text-[#d94e61]">92%</span>
                            </div>
                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                <div className="h-full rounded-full bg-[#d94e61]" style={{ width: '92%' }} />
                            </div>
                        </div>

                        <div>
                            <div className="flex justify-between text-xs mb-1.5">
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Michael Chen</span>
                                <span className="font-semibold text-[#da972e]">78%</span>
                            </div>
                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                <div className="h-full rounded-full bg-[#da972e]" style={{ width: '78%' }} />
                            </div>
                        </div>

                        <div>
                            <div className="flex justify-between text-xs mb-1.5">
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Chloe Duong</span>
                                <span className="font-semibold text-[#14b8a6]">63%</span>
                            </div>
                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                <div className="h-full rounded-full bg-[#14b8a6]" style={{ width: '63%' }} />
                            </div>
                        </div>

                        <div>
                            <div className="flex justify-between text-xs mb-1.5">
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Ben Tanaka</span>
                                <span className="font-semibold text-[#1f9b69]">51%</span>
                            </div>
                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                <div className="h-full rounded-full bg-[#1f9b69]" style={{ width: '51%' }} />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}