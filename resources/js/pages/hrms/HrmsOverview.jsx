import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import MetricCard from '../../components/ui/MetricCard';
import Button from '../../components/ui/Button';
import api from '../../services/api';

/**
 * 01 — HRMS Overview Hub
 * Exact pixel-perfect implementation of `figma/web-design/01 — HRMS Overview Hub.png`
 */
export default function HrmsOverview() {
    const navigate = useNavigate();
    usePageTitle('HRMS Overview');
    useSetCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Overview' }]);

    const [stats, setStats] = useState({
        total_employees: 360,
        on_leave_today: 28,
        new_joiners: 12,
        attendance_rate: '96.4%',
    });

    useEffect(() => {
        api.get('/hrms/analytics/headcount')
            .then(({ data }) => {
                if (data && data.total) {
                    setStats((prev) => ({
                        ...prev,
                        total_employees: data.total || 360,
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
                    Overview hub
                </h1>
                <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                    People operations at a glance · October 2026
                </p>
            </div>

            {/* Top 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Total employees"
                    value={stats.total_employees}
                    badge="+12%"
                    badgeVariant="success"
                    accentColor="#4b5ef5"
                    progress={85}
                />
                <MetricCard
                    title="On leave today"
                    value={stats.on_leave_today}
                    badge="-5%"
                    badgeVariant="success"
                    accentColor="#14b8a6"
                    progress={40}
                />
                <MetricCard
                    title="New joiners · 30d"
                    value={stats.new_joiners}
                    badge="+8%"
                    badgeVariant="success"
                    accentColor="#8257e5"
                    progress={65}
                />
                <MetricCard
                    title="Attendance rate"
                    value={stats.attendance_rate}
                    badge="+2%"
                    badgeVariant="success"
                    accentColor="#1f9b69"
                    progress={96}
                />
            </div>

            {/* Middle Row: Employee Distribution Donut & Leave Overview Progress */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* Employee Distribution */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                        Employee distribution
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Active headcount by department
                    </p>

                    <div className="mt-6 flex flex-col items-center justify-center gap-8 sm:flex-row">
                        {/* Donut Graphic */}
                        <div className="relative flex h-40 w-40 shrink-0 items-center justify-center">
                            <svg className="h-full w-full -rotate-90 transform" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="40" stroke="#f1f5f9" strokeWidth="14" fill="none" />
                                <circle cx="50" cy="50" r="40" stroke="#4b5ef5" strokeWidth="14" fill="none" strokeDasharray="100.5 151" strokeDashoffset="0" />
                                <circle cx="50" cy="50" r="40" stroke="#14b8a6" strokeWidth="14" fill="none" strokeDasharray="37.7 213.6" strokeDashoffset="-100.5" />
                                <circle cx="50" cy="50" r="40" stroke="#8257e5" strokeWidth="14" fill="none" strokeDasharray="30.1 221" strokeDashoffset="-138.2" />
                                <circle cx="50" cy="50" r="40" stroke="#da972e" strokeWidth="14" fill="none" strokeDasharray="30.1 221" strokeDashoffset="-168.3" />
                                <circle cx="50" cy="50" r="40" stroke="#1f9b69" strokeWidth="14" fill="none" strokeDasharray="20.1 231" strokeDashoffset="-198.4" />
                            </svg>
                            <div className="absolute text-center">
                                <div className="text-2xl font-bold text-[#0f172a] dark:text-[#f8fafc]">360</div>
                                <div className="text-[10px] text-[#64748b] dark:text-[#94a3b8]">Employees</div>
                            </div>
                        </div>

                        {/* Legend */}
                        <div className="w-full flex-1 space-y-3">
                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-[#4b5ef5]" />
                                    <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Engineering</span>
                                </span>
                                <span className="font-semibold text-[#64748b] dark:text-[#94a3b8]">40%</span>
                            </div>
                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-[#14b8a6]" />
                                    <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Design</span>
                                </span>
                                <span className="font-semibold text-[#64748b] dark:text-[#94a3b8]">15%</span>
                            </div>
                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-[#8257e5]" />
                                    <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Marketing</span>
                                </span>
                                <span className="font-semibold text-[#64748b] dark:text-[#94a3b8]">12%</span>
                            </div>
                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-[#da972e]" />
                                    <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Sales</span>
                                </span>
                                <span className="font-semibold text-[#64748b] dark:text-[#94a3b8]">12%</span>
                            </div>
                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-[#1f9b69]" />
                                    <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">People</span>
                                </span>
                                <span className="font-semibold text-[#64748b] dark:text-[#94a3b8]">8%</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Leave Overview */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                        Leave overview
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Available days across leave types
                    </p>

                    <div className="mt-6 space-y-5">
                        <div>
                            <div className="flex justify-between text-xs mb-1.5">
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">PTO</span>
                                <span className="font-semibold text-[#64748b] dark:text-[#94a3b8]">18 / 25</span>
                            </div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                <div className="h-full rounded-full bg-[#1f9b69]" style={{ width: '72%' }} />
                            </div>
                        </div>

                        <div>
                            <div className="flex justify-between text-xs mb-1.5">
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Sick leave</span>
                                <span className="font-semibold text-[#64748b] dark:text-[#94a3b8]">4 / 10</span>
                            </div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                <div className="h-full rounded-full bg-[#14b8a6]" style={{ width: '40%' }} />
                            </div>
                        </div>

                        <div>
                            <div className="flex justify-between text-xs mb-1.5">
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Comp-off</span>
                                <span className="font-semibold text-[#64748b] dark:text-[#94a3b8]">1 / 3</span>
                            </div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                <div className="h-full rounded-full bg-[#da972e]" style={{ width: '33%' }} />
                            </div>
                        </div>

                        <div>
                            <div className="flex justify-between text-xs mb-1.5">
                                <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Maternity</span>
                                <span className="font-semibold text-[#64748b] dark:text-[#94a3b8]">0 / 3</span>
                            </div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#1e2534]">
                                <div className="h-full rounded-full bg-[#94a3b8]" style={{ width: '0%' }} />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Bottom Row: Upcoming Approvals & Workforce Pulse */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Upcoming Approvals */}
                <div className="lg:col-span-2 rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                        Upcoming approvals
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Pending requests awaiting review
                    </p>

                    <div className="mt-5 divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                        {/* Approval Row 1 */}
                        <div className="flex items-center justify-between py-3.5 first:pt-0">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#4b5ef5] text-xs font-bold text-white">
                                    JL
                                </div>
                                <div>
                                    <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                        Jordan Lee
                                    </div>
                                    <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                        Annual leave · 3 days
                                    </div>
                                </div>
                            </div>
                            <button
                                onClick={() => navigate('/hrms/leave')}
                                className="rounded-lg bg-[#fff3d9] px-3.5 py-1 text-xs font-semibold text-[#da972e] transition hover:bg-[#fae6c0]"
                            >
                                Review
                            </button>
                        </div>

                        {/* Approval Row 2 */}
                        <div className="flex items-center justify-between py-3.5">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#14b8a6] text-xs font-bold text-white">
                                    AC
                                </div>
                                <div>
                                    <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                        Alex Chen
                                    </div>
                                    <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                        Travel expense · ₹35k
                                    </div>
                                </div>
                            </div>
                            <button
                                onClick={() => navigate('/hrms/inbox')}
                                className="rounded-lg bg-[#fff3d9] px-3.5 py-1 text-xs font-semibold text-[#da972e] transition hover:bg-[#fae6c0]"
                            >
                                Review
                            </button>
                        </div>

                        {/* Approval Row 3 */}
                        <div className="flex items-center justify-between py-3.5">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#8257e5] text-xs font-bold text-white">
                                    SK
                                </div>
                                <div>
                                    <div className="text-xs font-semibold text-[#0f172a] dark:text-[#f8fafc]">
                                        Samira Khan
                                    </div>
                                    <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                        Remote work · 2 days
                                    </div>
                                </div>
                            </div>
                            <button
                                onClick={() => navigate('/hrms/leave')}
                                className="rounded-lg bg-[#fff3d9] px-3.5 py-1 text-xs font-semibold text-[#da972e] transition hover:bg-[#fae6c0]"
                            >
                                Review
                            </button>
                        </div>
                    </div>
                </div>

                {/* Workforce Pulse */}
                <div className="flex flex-col justify-between rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div>
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                            Workforce pulse
                        </h2>
                        <p className="mt-0.5 text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Current organization health
                        </p>

                        <div className="mt-5 space-y-4">
                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-[#1f9b69]" />
                                    <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Present</span>
                                </span>
                                <span className="text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">312</span>
                            </div>

                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-[#4b5ef5]" />
                                    <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Remote</span>
                                </span>
                                <span className="text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">42</span>
                            </div>

                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-[#da972e]" />
                                    <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Late arrivals</span>
                                </span>
                                <span className="text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">6</span>
                            </div>

                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-[#d94e61]" />
                                    <span className="font-medium text-[#0f172a] dark:text-[#f8fafc]">Absent</span>
                                </span>
                                <span className="text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">4</span>
                            </div>
                        </div>
                    </div>

                    <div className="mt-6 border-t border-[#f1f5f9] pt-4 dark:border-[#232b3e]">
                        <Button
                            variant="secondary"
                            onClick={() => navigate('/hrms/attendance')}
                            className="w-full text-center"
                        >
                            Open attendance
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
