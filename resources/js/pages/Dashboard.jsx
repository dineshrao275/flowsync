import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
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
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { useAuth } from '../context/AuthContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

function chartDate(iso) {
    const d = new Date(`${iso}T00:00:00`);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function formatHours(value) {
    return `${Number(value).toFixed(1)}h`;
}

function chartTooltipStyle() {
    return {
        backgroundColor: '#ffffff',
        border: '1px solid #e5e7eb',
        borderRadius: '8px',
        fontSize: '12px',
        boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
    };
}

const axisProps = { tick: { fontSize: 11, fill: '#9aa3b2' }, tickMargin: 8 };
const tooltipProps = {
    contentStyle: chartTooltipStyle(),
    cursor: { fill: 'rgba(99,102,241,0.06)' },
};

function ProjectProgressChart({ projects }) {
    if (projects.length === 0) {
        return <p className="py-8 text-center text-sm text-gray-400">No projects to report on yet.</p>;
    }
    const data = projects.map((p) => ({ name: p.name, Open: p.open, Done: p.done }));

    return (
        <ResponsiveContainer width="100%" height={Math.max(160, data.length * 44)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                <XAxis type="number" allowDecimals={false} {...axisProps} />
                <YAxis type="category" dataKey="name" width={90} {...axisProps} />
                <Tooltip {...tooltipProps} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <BarShape dataKey="Open" stackId="a" fill="#94a3b8" radius={[0, 0, 0, 0]} />
                <BarShape dataKey="Done" stackId="a" fill="#6366f1" radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function HoursLoggedChart({ daily }) {
    const data = daily.map((d) => ({ date: chartDate(d.date), hours: Math.round((d.minutes / 60) * 10) / 10 }));

    if (data.every((d) => d.hours === 0)) {
        return <p className="py-8 text-center text-sm text-gray-400">No work logged in the last 14 days.</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <BarChart data={data} margin={{ top: 4, right: 8, left: -18, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="date" interval={2} {...axisProps} />
                <YAxis allowDecimals={false} {...axisProps} />
                <Tooltip {...tooltipProps} formatter={(value) => [formatHours(value), 'Logged']} />
                <BarShape dataKey="hours" fill="#6366f1" radius={[4, 4, 0, 0]} maxBarSize={28} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function TasksCreatedChart({ daily }) {
    const data = daily.map((d) => ({ date: chartDate(d.date), created: d.count }));

    if (data.every((d) => d.created === 0)) {
        return <p className="py-8 text-center text-sm text-gray-400">No tasks created in the last 14 days.</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <LineChart data={data} margin={{ top: 4, right: 8, left: -18, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="date" interval={2} {...axisProps} />
                <YAxis allowDecimals={false} {...axisProps} />
                <Tooltip {...tooltipProps} />
                <Line
                    type="monotone"
                    dataKey="created"
                    stroke="#6366f1"
                    strokeWidth={2}
                    dot={{ r: 2.5, fill: '#6366f1' }}
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
                    <span className="text-gray-450">{task.key}</span> · {task.title}
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
                <p className="py-8 text-center text-sm text-gray-400">{emptyText}</p>
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
const recentLabel = (task) => `${task.project.name} · updated ${new Date(task.updated_at).toLocaleDateString()}`;

export default function Dashboard() {
    usePageTitle('Dashboard');
    const [data, setData] = useState(null);
    const [analytics, setAnalytics] = useState(null);
    const [error, setError] = useState(null);
    const { user } = useAuth();
    const setCrumbs = useSetCrumbs();

    useEffect(() => {
        setCrumbs([{ label: 'Dashboard' }]);
    }, [setCrumbs]);

    useEffect(() => {
        api.get('/dashboard')
            .then(({ data: response }) => setData(response))
            .catch(() => setError('Unable to load the dashboard.'));
        api.get('/analytics/overview')
            .then(({ data: response }) => setAnalytics(response))
            .catch(() => setAnalytics(null));
    }, []);

    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!data) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    const counts = [
        {
            label: 'My open tasks',
            value: data.counts.my_open,
            icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2m-4-2v4m0 0l2-2m-2 2L9 5',
        },
        {
            label: 'Overdue',
            value: data.counts.my_overdue,
            icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            danger: true,
        },
        {
            label: 'Due soon (7d)',
            value: data.counts.my_due_soon,
            icon: 'M8 7V3m8 4V3M3 11h18M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z',
        },
        {
            label: 'In progress',
            value: data.counts.in_progress,
            icon: 'M13 10V3L4 14h7v7l9-11h-7z',
        },
        {
            label: 'All open',
            value: data.counts.open,
            icon: 'M3 17l9-9 9 9M3 21h18',
        },
        {
            label: 'Completed',
            value: data.counts.done,
            icon: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        },
    ];

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">
                    {greeting}, {user?.name?.split(' ')[0]}
                </h2>
                <p className="mt-1 text-sm text-gray-500">
                    Your tasks across {data.counts.open} open and {data.counts.done} completed.
                </p>
            </div>

            <div className="grid grid-cols-2 gap-4 xl:grid-cols-6">
                {counts.map((stat, index) => (
                    <div
                        key={stat.label}
                        className="animate-fade-in-up rounded-xl border border-gray-200/70 p-4 shadow-sm"
                        style={{ backgroundColor: 'var(--card-bg)', animationDelay: `${index * 50}ms` }}
                    >
                        <p className="truncate text-xs font-medium text-gray-500">{stat.label}</p>
                        <p className={`mt-1 text-2xl font-bold ${stat.danger && stat.value > 0 ? 'text-red-600' : 'text-gray-900'}`}>
                            {stat.value}
                        </p>
                    </div>
                ))}
            </div>

            {analytics && (
                <>
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <Card title="Project progress" subtitle="Open vs completed tasks by project">
                            <ProjectProgressChart projects={analytics.projects_progress || []} />
                        </Card>
                        <Card
                            title="Hours logged"
                            subtitle={`Last 14 days · ${formatHours((analytics.work_logs?.total_minutes || 0) / 60)} total`}
                        >
                            <HoursLoggedChart daily={analytics.work_logs?.daily || []} />
                        </Card>
                        <Card
                            title="Tasks created"
                            subtitle={`14-day trend · ${analytics.counts?.created_30d || 0} created in 30d`}
                        >
                            <TasksCreatedChart daily={analytics.tasks_created?.daily || []} />
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