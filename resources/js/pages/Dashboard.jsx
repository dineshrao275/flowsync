import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { gsap } from 'gsap';
import {
    CalendarBlank,
    CheckCircle,
    ClipboardText,
    ClockCountdown,
    Lightning,
    Stack,
} from '@phosphor-icons/react';
import {
    Bar as BarShape,
    BarChart,
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { projectUrl } from '../utils/deepLinks';
import api from '../services/api';
import Button from '../components/ui/Button';
import Card from '../components/ui/Card';
import EmptyState from '../components/ui/EmptyState';
import Skeleton from '../components/ui/Skeleton';
import Alert from '../components/ui/Alert';
import RangeFilter from '../components/reports/RangeFilter';
import { resolveRange, rangeLabel } from '../utils/dateRange';
import { useAuth } from '../context/AuthContext';
import { useTheme } from '../context/ThemeContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import Timestamp from '../components/ui/Timestamp';

function chartDate(iso) {
    const d = new Date(`${iso}T00:00:00`);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function formatHours(value) {
    return `${Number(value).toFixed(1)}h`;
}

// The admin's accent lives on a CSS token, so charts read it back instead
// of hardcoding a brand hex — a re-themed tenant re-skins its analytics.
function accentHex() {
    if (typeof document === 'undefined') return '#2563eb';

    return getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#2563eb';
}

function chartPalette(mode) {
    const dark = mode === 'dark';

    return {
        dark,
        accent: accentHex(),
        secondary: '#94a3b8',
        tick: dark ? '#a7afbe' : '#64748b',
        grid: dark ? '#28303f' : '#e2e8f0',
        tooltip: {
            backgroundColor: 'var(--card-bg)',
            border: `1px solid ${dark ? '#28303f' : '#e5e7eb'}`,
            borderRadius: '8px',
            fontSize: '12px',
            color: dark ? '#e8eaef' : '#1f2937',
            boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
        },
    };
}

function axisProps(mode) {
    return { tick: { fontSize: 11, fill: chartPalette(mode).tick }, tickMargin: 8 };
}

function tooltipProps(mode) {
    const palette = chartPalette(mode);

    return {
        contentStyle: palette.tooltip,
        cursor: { fill: palette.dark ? 'rgba(167,175,190,0.12)' : 'rgba(37,99,235,0.06)' },
    };
}

function ChartEmpty({ title, actionLabel, onAction }) {
    return (
        <EmptyState
            title={title}
            action={onAction ? <Button size="sm" variant="subtle" onClick={onAction}>{actionLabel}</Button> : null}
        />
    );
}

function ProjectProgressChart({ projects, mode, onBrowse }) {
    const palette = chartPalette(mode);

    if (projects.length === 0) {
        return <ChartEmpty title="No projects to report on yet." actionLabel="Browse projects" onAction={onBrowse} />;
    }
    const data = projects.map((p) => ({ name: p.name, Open: p.open, Done: p.done }));

    return (
        <ResponsiveContainer width="100%" height={Math.max(160, data.length * 44)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke={palette.grid} />
                <XAxis type="number" allowDecimals={false} {...axisProps(mode)} />
                <YAxis type="category" dataKey="name" width={90} {...axisProps(mode)} />
                <Tooltip {...tooltipProps(mode)} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <BarShape dataKey="Open" stackId="a" fill={palette.secondary} radius={[0, 0, 0, 0]} />
                <BarShape dataKey="Done" stackId="a" fill={palette.accent} radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function HoursLoggedChart({ daily, mode, onBrowse }) {
    const palette = chartPalette(mode);
    const data = daily.map((d) => ({ date: chartDate(d.date), hours: Math.round((d.minutes / 60) * 10) / 10 }));

    if (data.every((d) => d.hours === 0)) {
        return <ChartEmpty title="No work logged in this range." actionLabel="Browse projects" onAction={onBrowse} />;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <BarChart data={data} margin={{ top: 4, right: 8, left: -18, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke={palette.grid} />
                <XAxis dataKey="date" interval={Math.max(0, Math.ceil(data.length / 8) - 1)} {...axisProps(mode)} />
                <YAxis allowDecimals={false} {...axisProps(mode)} />
                <Tooltip {...tooltipProps(mode)} formatter={(value) => [formatHours(value), 'Logged']} />
                <BarShape dataKey="hours" fill={palette.accent} radius={[4, 4, 0, 0]} maxBarSize={28} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function TasksCreatedChart({ daily, mode, onBrowse }) {
    const palette = chartPalette(mode);
    const data = daily.map((d) => ({ date: chartDate(d.date), created: d.count }));

    if (data.every((d) => d.created === 0)) {
        return <ChartEmpty title="No tasks created in this range." actionLabel="Browse projects" onAction={onBrowse} />;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <LineChart data={data} margin={{ top: 4, right: 8, left: -18, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke={palette.grid} />
                <XAxis dataKey="date" interval={Math.max(0, Math.ceil(data.length / 8) - 1)} {...axisProps(mode)} />
                <YAxis allowDecimals={false} {...axisProps(mode)} />
                <Tooltip {...tooltipProps(mode)} />
                <Line
                    type="monotone"
                    dataKey="created"
                    stroke={palette.accent}
                    strokeWidth={2}
                    dot={data.length > 60 ? false : { r: 2.5, fill: palette.accent }}
                    activeDot={{ r: 4 }}
                />
            </LineChart>
        </ResponsiveContainer>
    );
}

function TaskRow({ task, dateLabel = null }) {
    return (
        <Link
            to={projectUrl(task.project.id, 'tasks')}
            className="flex items-start gap-3 rounded-lg px-2 py-2 transition hover:bg-gray-50"
        >
            <span
                className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                style={{ backgroundColor: task.priority?.color || '#94a3b8' }}
            />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium text-gray-800">
                    <span className="text-gray-400">{task.key}</span> · {task.title}
                </span>
                <span className="mt-0.5 block text-xs text-gray-400">{dateLabel}</span>
            </span>
        </Link>
    );
}

function TaskList({ tasks, title, subtitle, emptyText, dateLabel }) {
    return (
        <Card title={title} subtitle={subtitle}>
            {tasks.length === 0 ? (
                <EmptyState description={emptyText} />
            ) : (
                <ul className="space-y-1">
                    {tasks.map((task) => (
                        <li key={task.id}>
                            <TaskRow task={task} dateLabel={dateLabel ? dateLabel(task) : null} />
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

const dueSoonLabel = (task) => `Due ${task.due_date} · ${task.project.name}`;
const overdueLabel = (task) => `Overdue · was due ${task.due_date}`;
const recentLabel = (task) => (
    <>
        {task.project.name} · updated <Timestamp date={task.updated_at} />
    </>
);

export default function Dashboard() {
    usePageTitle('Dashboard');
    const [data, setData] = useState(null);
    const [analytics, setAnalytics] = useState(null);
    const [error, setError] = useState(null);
    const [preset, setPreset] = useState('month');
    const [custom, setCustom] = useState(null);
    const range = resolveRange(preset, custom ?? {});
    const { user } = useAuth();
    const { resolvedMode } = useTheme();
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const heroRef = useRef(null);
    const loaded = data !== null;

    useEffect(() => {
        setCrumbs([{ label: 'Dashboard' }]);
    }, [setCrumbs]);

    useEffect(() => {
        api.get('/dashboard')
            .then(({ data: response }) => setData(response))
            .catch(() => setError('Unable to load the dashboard.'));
    }, []);

    useEffect(() => {
        api.get('/analytics/overview', { params: { from: range.from, to: range.to } })
            .then(({ data: response }) => setAnalytics(response))
            .catch(() => setAnalytics(null));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [range.from, range.to]);

    // The one orchestrated moment on this page: the greeting settles in
    // once the counts land. Reduced motion renders the final state.
    useLayoutEffect(() => {
        if (!loaded || !heroRef.current) return undefined;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return undefined;

        const ctx = gsap.context(() => {
            gsap.from('[data-hero-reveal]', { opacity: 0, y: 12, duration: 0.35, ease: 'power1.out' });
        }, heroRef);

        return () => ctx.revert();
    }, [loaded]);

    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!data) {
        return (
            <div className="space-y-6" aria-busy="true" aria-label="Loading dashboard">
                <div>
                    <Skeleton variant="text" className="h-8 w-64" />
                    <Skeleton variant="text" className="mt-2 w-96 max-w-full" />
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Skeleton variant="block" className="h-28" />
                    <Skeleton variant="block" className="h-28" />
                </div>
                <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
                    <Skeleton variant="block" className="h-24" />
                    <Skeleton variant="block" className="h-24" />
                    <Skeleton variant="block" className="h-24" />
                    <Skeleton variant="block" className="h-24" />
                </div>
            </div>
        );
    }

    const heroes = [
        {
            label: 'My open tasks',
            value: data.counts.my_open,
            hint: data.scope === 'all' ? 'Every task in the tenant' : 'Tasks in your projects',
            icon: ClipboardText,
        },
        {
            label: 'Overdue',
            value: data.counts.my_overdue,
            hint: 'Open tasks past their due date',
            icon: ClockCountdown,
            danger: true,
        },
    ];
    const stats = [
        { label: 'Due soon', value: data.counts.my_due_soon, hint: 'Next 7 days', icon: CalendarBlank },
        { label: 'In progress', value: data.counts.in_progress, hint: 'Being worked on', icon: Lightning },
        { label: 'All open', value: data.counts.open, hint: 'Across the tenant', icon: Stack },
        { label: 'Completed', value: data.counts.done, hint: 'All done', icon: CheckCircle },
    ];

    return (
        <div className="space-y-6">
            <div ref={heroRef}>
                <h2 data-hero-reveal className="text-2xl font-bold text-gray-900">
                    {greeting}, {user?.name?.split(' ')[0]}
                </h2>
                <p data-hero-reveal className="mt-1 text-sm text-gray-500">
                    Your tasks across {data.counts.open} open and {data.counts.done} completed.
                </p>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {heroes.map((stat) => {
                    const StatIcon = stat.icon;
                    return (
                        <div
                            key={stat.label}
                            className="flex items-center gap-4 rounded-xl border border-gray-200/70 p-5 shadow-card"
                            style={{ backgroundColor: 'var(--card-bg)' }}
                        >
                            <span
                                className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${
                                    stat.danger && stat.value > 0
                                        ? 'bg-red-100 text-red-600'
                                        : 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
                                }`}
                            >
                                <StatIcon size={24} aria-hidden="true" />
                            </span>
                            <span className="min-w-0">
                                <span className={`tnum block text-3xl font-bold ${stat.danger && stat.value > 0 ? 'text-red-600' : 'text-gray-900'}`}>
                                    {stat.value}
                                </span>
                                <span className="block truncate text-sm font-medium text-gray-700">{stat.label}</span>
                                <span className="block truncate text-xs text-gray-400">{stat.hint}</span>
                            </span>
                        </div>
                    );
                })}
            </div>

            <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
                {stats.map((stat) => {
                    const StatIcon = stat.icon;
                    return (
                        <div
                            key={stat.label}
                            className="flex items-center gap-3 rounded-xl border border-gray-200/70 p-4 shadow-card"
                            style={{ backgroundColor: 'var(--card-bg)' }}
                        >
                            <StatIcon size={20} aria-hidden="true" className="shrink-0 text-gray-400" />
                            <span className="min-w-0">
                                <span className="tnum block text-2xl font-bold text-gray-900">{stat.value}</span>
                                <span className="block truncate text-xs font-medium text-gray-500">
                                    {stat.label} · {stat.hint}
                                </span>
                            </span>
                        </div>
                    );
                })}
            </div>

            <RangeFilter
                preset={preset}
                onChange={(key, customRange) => {
                    setPreset(key);
                    setCustom(customRange);
                }}
            />

            {analytics && (
                <>
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <Card title="Project progress" subtitle="Open vs completed tasks by project">
                            <ProjectProgressChart projects={analytics.projects_progress || []} mode={resolvedMode} onBrowse={() => navigate('/projects')} />
                        </Card>
                        <Card
                            title="Hours logged"
                            subtitle={`${rangeLabel(preset, range)} · ${formatHours((analytics.work_logs?.total_minutes || 0) / 60)} total`}
                        >
                            <HoursLoggedChart daily={analytics.work_logs?.daily || []} mode={resolvedMode} onBrowse={() => navigate('/projects')} />
                        </Card>
                        <Card
                            title="Tasks created"
                            subtitle={`${rangeLabel(preset, range)} · ${analytics.counts?.created_in_range ?? 0} created`}
                        >
                            <TasksCreatedChart daily={analytics.tasks_created?.daily || []} mode={resolvedMode} onBrowse={() => navigate('/projects')} />
                        </Card>
                    </div>

                    {analytics.top_contributors?.length > 0 && (
                        <Card title="Top contributors" subtitle="Most time logged in the last 30 days">
                            <ul className="divide-y divide-gray-100">
                                {analytics.top_contributors.map((c) => (
                                    <li key={c.user?.id} className="flex items-center justify-between py-2.5">
                                        <span className="flex items-center gap-3">
                                            <span
                                                className="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold text-white"
                                                style={{ backgroundColor: 'var(--accent)' }}
                                            >
                                                {(c.user?.name || '?').charAt(0)}
                                            </span>
                                            <span className="text-sm font-medium text-gray-800">{c.user?.name || 'Unknown'}</span>
                                        </span>
                                        <span className="text-sm text-gray-500">
                                            {formatHours(c.minutes / 60)} · {c.logs_count} logs
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <TaskList tasks={data.my_overdue} title="Overdue" subtitle="Open tasks past their due date" emptyText="Nothing overdue." dateLabel={overdueLabel} />
                <TaskList tasks={data.my_due_soon} title="Due soon" subtitle="Next seven days, assigned to you" emptyText="Nothing due in the next week." dateLabel={dueSoonLabel} />

                <TaskList tasks={data.in_progress} title="In progress" subtitle="Currently being worked on" emptyText="No tasks in progress." dateLabel={recentLabel} />
                <TaskList tasks={data.my_open} title="Recently updated" subtitle="Your open tasks, newest activity first" emptyText="No recent changes." dateLabel={() => 'Assigned to you'} />
            </div>
        </div>
    );
}