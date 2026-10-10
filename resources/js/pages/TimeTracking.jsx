import { useEffect, useState } from 'react';
import MetricCard from '../components/ui/MetricCard';
import StatusPill from '../components/ui/StatusPill';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';

const WEEKLY_SUMMARY = [
    { project: 'Q4 Roadmap', duration: '14h 20m', percentage: 50, color: '#4B5EF5' },
    { project: 'Platform reliability', duration: '10h 45m', percentage: 38, color: '#1F9B69' },
    { project: 'Mobile app', duration: '6h 10m', percentage: 22, color: '#7B61FF' },
    { project: 'Team & admin', duration: '4h 00m', percentage: 15, color: '#DA972E' },
];

const RECENT_WORK_LOGS = [
    {
        id: 1,
        date: 'Oct 10',
        taskProject: 'WEB-42 · Search & filtering',
        duration: '2h 30m',
        type: 'Billable',
        approver: 'Alex Rivera',
        status: 'Pending',
        variant: 'warning',
    },
    {
        id: 2,
        date: 'Oct 10',
        taskProject: 'Platform monitoring',
        duration: '1h 00m',
        type: 'Non-billable',
        approver: 'Elena Rostova',
        status: 'Approved',
        variant: 'healthy',
    },
    {
        id: 3,
        date: 'Oct 09',
        taskProject: 'Q4 Roadmap planning',
        duration: '3h 20m',
        type: 'Billable',
        approver: 'Alex Rivera',
        status: 'Approved',
        variant: 'healthy',
    },
];

export default function TimeTracking() {
    usePageTitle('Time tracking');
    const toast = useToast();

    const [seconds, setSeconds] = useState(8076); // 02:14:36
    const [running, setRunning] = useState(true);

    useEffect(() => {
        let interval;
        if (running) {
            interval = setInterval(() => {
                setSeconds((s) => s + 1);
            }, 1000);
        }
        return () => clearInterval(interval);
    }, [running]);

    const formatTime = (totalSec) => {
        const h = Math.floor(totalSec / 3600);
        const m = Math.floor((totalSec % 3600) / 60);
        const s = totalSec % 60;
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    };

    function handlePause() {
        setRunning((r) => !r);
        toast.info(running ? 'Timer paused.' : 'Timer resumed.');
    }

    function handleStop() {
        setRunning(false);
        toast.success(`Logged ${formatTime(seconds)} to WEB-42.`);
    }

    return (
        <div className="space-y-6">
            {/* Header */}
            <div>
                <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">Time tracking</h1>
                <p className="mt-1 text-[13px] text-[#5A6478]">
                    Capture work, manage billable time and submit work logs for approval.
                </p>
            </div>

            {/* 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="Time logged today"
                    value="04h 30m"
                    pillText="Today"
                    pillVariant="healthy"
                    accentColor="#4B5EF5"
                />
                <MetricCard
                    label="This week"
                    value="31h 15m"
                    pillText="+6%"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
                <MetricCard
                    label="Awaiting approval"
                    value="8 entries"
                    pillText="Review"
                    pillVariant="healthy"
                    accentColor="#DA972E"
                />
                <MetricCard
                    label="Billable utilization"
                    value="78%"
                    pillText="+4%"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
            </div>

            {/* Middle Section: Active timer (Left) & Weekly summary (Right) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                {/* Left: Active timer */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-5">
                    <div className="mb-4 flex items-center justify-between">
                        <div>
                            <h2 className="text-[16px] font-semibold text-[#171C2C]">Active timer</h2>
                            <p className="mt-0.5 text-[12px] text-[#8C96A8]">WEB-42 · Implement advanced search</p>
                        </div>
                        {running && (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-[#E6F7EF] px-2.5 py-0.5 text-[11px] font-medium text-[#1F9B69]">
                                <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-[#1F9B69]" />
                                Recording
                            </span>
                        )}
                    </div>

                    <div className="my-6">
                        <div className="font-mono text-[36px] font-bold tracking-tight text-[#171C2C]">
                            {formatTime(seconds)}
                        </div>
                        <p className="mt-1 text-[13px] text-[#5A6478]">Q4 Roadmap · Search & filtering</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 pt-2">
                        <button
                            type="button"
                            onClick={handlePause}
                            className="rounded-lg bg-[#4B5EF5] px-4 py-2 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                        >
                            {running ? 'Pause timer' : 'Resume timer'}
                        </button>
                        <button
                            type="button"
                            onClick={handleStop}
                            className="rounded-lg border border-[#E5E8F0] bg-white px-4 py-2 text-[13px] font-medium text-[#171C2C] hover:bg-[#F8FAFD] transition-colors shadow-xs"
                        >
                            Stop & log
                        </button>
                        <button
                            type="button"
                            onClick={() => toast.info('Navigating to issue WEB-42...')}
                            className="rounded-lg bg-[#E9ECFF] px-4 py-2 text-[13px] font-semibold text-[#4B5EF5] hover:bg-[#DDE3FF] transition-colors"
                        >
                            View issue
                        </button>
                    </div>
                </div>

                {/* Right: Weekly summary */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-7">
                    <div className="mb-6">
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">Weekly summary</h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">Logged by project and work type</p>
                    </div>

                    <div className="space-y-5">
                        {WEEKLY_SUMMARY.map((item) => (
                            <div key={item.project} className="space-y-1.5">
                                <div className="flex items-center justify-between text-[13px]">
                                    <span className="font-medium text-[#171C2C]">{item.project}</span>
                                    <span className="text-[12px] font-semibold text-[#5A6478]">{item.duration}</span>
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

            {/* Bottom Card: Recent work logs */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-4">
                    <h2 className="text-[16px] font-semibold text-[#171C2C]">Recent work logs</h2>
                    <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                        Entries can be approved by project lead and exported for workforce reporting.
                    </p>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="border-b border-[#F0F2F7] text-[11px] font-semibold uppercase tracking-wider text-[#8C96A8]">
                                <th className="pb-3 pl-2">DATE</th>
                                <th className="pb-3">TASK / PROJECT</th>
                                <th className="pb-3">DURATION</th>
                                <th className="pb-3">TYPE</th>
                                <th className="pb-3">APPROVER</th>
                                <th className="pb-3 text-right pr-2">STATUS</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#F0F2F7]">
                            {RECENT_WORK_LOGS.map((log) => (
                                <tr key={log.id} className="hover:bg-[#F8FAFD] transition-colors">
                                    <td className="py-3.5 pl-2 text-[13px] text-[#8C96A8]">{log.date}</td>
                                    <td className="py-3.5 text-[13px] font-medium text-[#171C2C]">{log.taskProject}</td>
                                    <td className="py-3.5 text-[13px] font-semibold text-[#171C2C]">{log.duration}</td>
                                    <td className="py-3.5 text-[13px] text-[#5A6478]">{log.type}</td>
                                    <td className="py-3.5 text-[13px] text-[#5A6478]">{log.approver}</td>
                                    <td className="py-3.5 text-right pr-2">
                                        <StatusPill variant={log.variant}>{log.status}</StatusPill>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
