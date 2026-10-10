import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import Button from '../components/ui/Button';
import StatusPill from '../components/ui/StatusPill';

/**
 * 00 — FlowSync Connected Suite
 * Rebuilt from `figma/svg-designs/web-design/00 — FlowSync Connected Suite.svg`:
 * hub cards 555×298 `rx12.5`, 54×54 icon tiles `rx14`, 154×87 metric tiles with
 * a 4px `#EEF1F7` progress track at 66%, and 34×34 `rx10` workflow squares in
 * brand/teal/purple.
 */
const HRMS_METRICS = [
    { title: 'Employees', value: '360', delta: '+12%', color: '#4b5ef5' },
    { title: 'On leave', value: '28', delta: '-5%', color: '#25a6a1' },
    { title: 'Approvals', value: '12', delta: '+3%', color: '#d58a16' },
];

const TMS_METRICS = [
    { title: 'Projects', value: '24', delta: '+4%', color: '#7858de' },
    { title: 'Open tasks', value: '186', delta: '-8%', color: '#4b5ef5' },
    { title: 'Delivery health', value: '92%', delta: '+6%', color: '#1f9b69' },
];

const WORKFLOWS = [
    { n: '01', color: '#4b5ef5', title: 'Employee joins', body: 'HRMS lifecycle event creates role-based onboarding tasks in TMS' },
    { n: '02', color: '#25a6a1', title: 'Leave gets approved', body: 'TMS capacity and sprint availability update automatically' },
    { n: '03', color: '#7858de', title: 'Time is logged', body: 'Approved work flows into workforce reporting and project summaries' },
];

const ACTIVITY = [
    { initials: 'AR', color: '#4b5ef5', name: 'Alex Rivera', body: 'Created Q4 Roadmap · 2 min ago' },
    { initials: 'SL', color: '#25a6a1', name: 'Sarah Lee', body: 'Approved annual leave · 18 min ago' },
    { initials: 'JM', color: '#7858de', name: 'Jordan Miller', body: 'Logged 2h 30m · 34 min ago' },
];

function MiniMetric({ title, value, delta, color }) {
    return (
        <div className="rounded-[12px] border border-[var(--border-hairline)] bg-[var(--card-bg)] px-3.5 py-3">
            <div className="flex items-start justify-between gap-2">
                <span className="text-[10px] font-medium text-muted">{title}</span>
                <StatusPill label={delta} variant="success" size="sm" className="!text-[10px]" />
            </div>
            <div className="mt-1.5 text-[20px] font-bold leading-none tracking-[-0.02em] text-ink">{value}</div>
            <div className="mt-2.5 h-1 w-full overflow-hidden rounded-full bg-[var(--track)]">
                <div className="h-full rounded-full" style={{ backgroundColor: color, width: '66%' }} />
            </div>
        </div>
    );
}

function HubCard({ eyebrow, title, subtitle, metrics, primary, primaryTo, secondary, secondaryTo, icon, iconClass }) {
    const navigate = useNavigate();
    return (
        <div className="flex flex-col justify-between rounded-[12px] border border-[var(--border-hairline)] bg-[var(--card-bg)] p-5 shadow-[var(--shadow-card)]">
            <div>
                <div className="text-[11px] font-semibold uppercase tracking-[0.05em] text-muted">{eyebrow}</div>
                <div className="mt-3 flex items-start gap-4">
                    <div className={`flex h-[54px] w-[54px] shrink-0 items-center justify-center rounded-[14px] ${iconClass}`}>
                        {icon}
                    </div>
                    <div className="min-w-0 flex-1 pt-0.5">
                        <h2 className="text-[18px] font-semibold leading-tight tracking-[-0.01em] text-ink">{title}</h2>
                        <p className="mt-1 text-[12px] leading-relaxed text-muted">{subtitle}</p>
                    </div>
                </div>
                <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {metrics.map((m) => (
                        <MiniMetric key={m.title} {...m} />
                    ))}
                </div>
            </div>
            <div className="mt-5 flex flex-wrap items-center gap-3 border-t border-[var(--border-hairline)] pt-4">
                <Button size="sm" onClick={() => navigate(primaryTo)}>
                    {primary}
                </Button>
                <Button size="sm" variant="secondary" onClick={() => navigate(secondaryTo)}>
                    {secondary}
                </Button>
            </div>
        </div>
    );
}

export default function SuiteHome() {
    const { user } = useAuth();
    const navigate = useNavigate();
    const setCrumbs = useSetCrumbs();
    const firstName = user?.name ? user.name.split(' ')[0] : 'Alex';

    useEffect(() => {
        setCrumbs([{ label: 'Suite home' }]);
    }, [setCrumbs]);

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-[28px] font-bold leading-tight tracking-[-0.02em] text-ink">
                        Good morning, {firstName}
                    </h1>
                    <p className="mt-1 text-[13px] text-muted">
                        Your people and delivery work, connected in one place.
                    </p>
                </div>
                <StatusPill label="All systems operational" variant="success" dot size="sm" />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <HubCard
                    eyebrow="FlowSync HRMS"
                    title="People, time and employee experience"
                    subtitle="Manage employee records, attendance, leave, payroll and approvals."
                    metrics={HRMS_METRICS}
                    primary="Open HRMS"
                    primaryTo="/hrms"
                    secondary="Employee directory"
                    secondaryTo="/hrms/employees"
                    iconClass="bg-[var(--accent-soft)] text-[var(--accent)]"
                    icon={
                        <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                    }
                />
                <HubCard
                    eyebrow="FlowSync TMS"
                    title="Plan, prioritize and deliver work"
                    subtitle="Manage projects, boards, sprints, roadmaps and work logs."
                    metrics={TMS_METRICS}
                    primary="Open TMS"
                    primaryTo="/dashboard"
                    secondary="Q4 Roadmap board"
                    secondaryTo="/projects"
                    iconClass="bg-[var(--status-purple-soft)] text-[var(--status-purple)]"
                    icon={
                        <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <rect x="3" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="14" width="7" height="7" rx="1" />
                            <rect x="3" y="14" width="7" height="7" rx="1" />
                        </svg>
                    }
                />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="rounded-[12px] border border-[var(--border-hairline)] bg-[var(--card-bg)] p-5 shadow-[var(--shadow-card)] lg:col-span-2">
                    <h3 className="text-[15px] font-semibold text-ink">Connected workflows</h3>
                    <p className="mt-0.5 text-[12px] text-muted">Shared platform services power both applications.</p>

                    <div className="mt-4">
                        {WORKFLOWS.map((w, i) => (
                            <div
                                key={w.n}
                                className={`flex items-center gap-4 py-4 ${i > 0 ? 'border-t border-[var(--border-hairline)]' : ''}`}
                            >
                                <div
                                    className="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[10px] text-[12px] font-bold text-white"
                                    style={{ backgroundColor: w.color }}
                                >
                                    {w.n}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13px] font-semibold text-ink">{w.title}</div>
                                    <div className="mt-0.5 text-[12px] text-muted">{w.body}</div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex flex-col justify-between rounded-[12px] border border-[var(--border-hairline)] bg-[var(--card-bg)] p-5 shadow-[var(--shadow-card)]">
                    <div>
                        <h3 className="text-[15px] font-semibold text-ink">Recent activity</h3>
                        <p className="mt-0.5 text-[12px] text-muted">Latest across connected apps</p>

                        <div className="mt-4">
                            {ACTIVITY.map((a, i) => (
                                <div
                                    key={a.name}
                                    className={`flex items-center gap-3 py-3.5 ${i > 0 ? 'border-t border-[var(--border-hairline)]' : ''}`}
                                >
                                    <div
                                        className="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white"
                                        style={{ backgroundColor: a.color }}
                                    >
                                        {a.initials}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[12px] font-semibold text-ink">{a.name}</div>
                                        <div className="text-[11px] text-muted">{a.body}</div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="mt-4 border-t border-[var(--border-hairline)] pt-4">
                        <Button variant="secondary" size="sm" onClick={() => navigate('/settings')} className="w-full">
                            Manage integrations
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
