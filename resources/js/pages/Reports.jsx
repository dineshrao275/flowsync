import { useState } from 'react';
import MetricCard from '../components/ui/MetricCard';
import usePageTitle from '../hooks/usePageTitle';

const TREND_DATA = [
    { label: 'Sep 15', primary: 65, secondary: 45 },
    { label: 'Sep 22', primary: 80, secondary: 55 },
    { label: 'Sep 29', primary: 110, secondary: 70 },
    { label: 'Oct 06', primary: 140, secondary: 90 },
    { label: 'Oct 13', primary: 85, secondary: 60 },
    { label: 'Oct 20', primary: 115, secondary: 75 },
    { label: 'Oct 27', primary: 130, secondary: 85 },
    { label: 'Nov 03', primary: 95, secondary: 70 },
];

const WORKLOAD_DATA = [
    { department: 'Engineering', percentage: 84, color: '#4B5EF5' },
    { department: 'Product', percentage: 71, color: '#1F9B69' },
    { department: 'Design', percentage: 63, color: '#7B61FF' },
    { department: 'People', percentage: 52, color: '#DA972E' },
];

const SAVED_REPORTS = [
    { id: 1, title: 'Sprint velocity', category: 'TMS delivery', updated: 'Updated today' },
    { id: 2, title: 'Attendance exceptions', category: 'HRMS people ops', updated: 'Updated 1h ago' },
    { id: 3, title: 'Capacity & availability', category: 'Cross-app analytics', updated: 'Updated 2h ago' },
];

export default function Reports() {
    usePageTitle('Reports & analytics');
    const [dateRange, setDateRange] = useState('Last 30 days');

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">Reports & analytics</h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Permission-aware insights across project delivery and workforce operations.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => {
                            setDateRange((r) => (r === 'Last 30 days' ? 'Last 90 days' : 'Last 30 days'));
                        }}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-[#E5E8F0] bg-white px-3.5 py-2 text-[12px] font-medium text-[#171C2C] hover:bg-[#F8FAFD] transition-colors shadow-xs"
                    >
                        <span>{dateRange}</span>
                        <span className="text-[10px] text-[#8C96A8]">⌄</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex items-center justify-center rounded-lg border border-[#E5E8F0] bg-white px-4 py-2 text-[13px] font-medium text-[#171C2C] shadow-xs hover:bg-[#F8FAFD] transition-colors"
                    >
                        Export report
                    </button>
                </div>
            </div>

            {/* 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="Work completed"
                    value="1,284"
                    pillText="+12%"
                    pillVariant="healthy"
                    accentColor="#4B5EF5"
                />
                <MetricCard
                    label="Average cycle time"
                    value="3.4 days"
                    pillText="-8%"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
                <MetricCard
                    label="Team utilization"
                    value="78%"
                    pillText="+4%"
                    pillVariant="healthy"
                    accentColor="#7B61FF"
                />
                <MetricCard
                    label="Employee availability"
                    value="91%"
                    pillText="+2%"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
            </div>

            {/* Middle Section: Trends (Left) & Workload (Right) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                {/* Left: Delivery trends */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-8">
                    <div className="mb-6">
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">Delivery trends</h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">Completed work items per week</p>
                    </div>

                    <div className="flex h-56 items-end justify-between gap-3 px-2 pt-6">
                        {TREND_DATA.map((item) => (
                            <div key={item.label} className="flex flex-1 flex-col items-center gap-2">
                                <div className="flex h-44 w-full items-end justify-center gap-1.5">
                                    <div
                                        className="w-4 rounded-t-sm bg-[#4B5EF5] transition-all hover:opacity-90"
                                        style={{ height: `${(item.primary / 160) * 100}%` }}
                                        title={`Primary: ${item.primary}`}
                                    />
                                    <div
                                        className="w-4 rounded-t-sm bg-[#1F9B69] transition-all hover:opacity-90"
                                        style={{ height: `${(item.secondary / 160) * 100}%` }}
                                        title={`Secondary: ${item.secondary}`}
                                    />
                                </div>
                                <span className="text-[11px] font-medium text-[#8C96A8]">{item.label}</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Right: Workload by department */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-4">
                    <div className="mb-6">
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">Workload by department</h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">Tasks active vs team capacity</p>
                    </div>

                    <div className="space-y-5">
                        {WORKLOAD_DATA.map((item) => (
                            <div key={item.department} className="space-y-1.5">
                                <div className="flex items-center justify-between text-[13px]">
                                    <span className="font-medium text-[#171C2C]">{item.department}</span>
                                    <span className="text-[12px] font-semibold text-[#5A6478]">{item.percentage}%</span>
                                </div>
                                <div className="h-2 w-full overflow-hidden rounded-full bg-[#F0F2F7]">
                                    <div
                                        className="h-full rounded-full transition-all"
                                        style={{ width: `${item.percentage}%`, backgroundColor: item.color }}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Bottom Card: Saved reports */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-4">
                    <h2 className="text-[16px] font-semibold text-[#171C2C]">Saved reports</h2>
                    <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                        Report visibility depends on permissions and subscription entitlements.
                    </p>
                </div>

                <div className="divide-y divide-[#F0F2F7]">
                    {SAVED_REPORTS.map((report) => (
                        <div key={report.id} className="flex items-center justify-between py-3.5 first:pt-1 last:pb-1">
                            <div>
                                <h3 className="text-[13px] font-medium text-[#171C2C]">{report.title}</h3>
                            </div>
                            <div className="text-[13px] text-[#5A6478]">{report.category}</div>
                            <div className="text-[13px] text-[#8C96A8]">{report.updated}</div>
                            <div>
                                <button
                                    type="button"
                                    onClick={() => alert(`Opening report: ${report.title}`)}
                                    className="rounded-lg border border-[#E5E8F0] bg-white px-4 py-1.5 text-[12px] font-medium text-[#171C2C] hover:bg-[#F8FAFD] transition-colors shadow-xs"
                                >
                                    Open
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
