import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import Avatar from '../components/ui/Avatar';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { gsap } from 'gsap';
import { CalendarBlank, CheckCircle, ClipboardText, ClockCountdown, Lightning, Stack } from '@phosphor-icons/react';
import { taskUrl } from '../utils/deepLinks';
import TaskBadge from '../components/ui/TaskBadge';
import api from '../services/api';
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
import { formatHours, ProjectProgressChart, HoursLoggedChart, TasksCreatedChart } from '../components/dashboard/DashboardCharts';

function TaskRow({ task, dateLabel = null }) {
    const to = taskUrl(task.project.id, task.key);
    return (
        <div className="flex items-start gap-3 rounded-lg px-2 py-2 transition hover:bg-gray-50">
            <span
                className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                style={{ backgroundColor: task.priority?.color || '#94a3b8' }}
            />
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2 truncate text-sm font-medium text-gray-800">
                    <TaskBadge taskKey={task.key} projectId={task.project.id} />
                    <Link to={to} className="truncate hover:text-[var(--accent)] hover:underline">
                        {task.title}
                    </Link>
                </span>
                <span className="mt-0.5 block text-xs text-gray-400">{dateLabel}</span>
            </span>
        </div>
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

const recentLabel = (task) => (
    <>
        {task.project.name} · updated <Timestamp date={task.updated_at} />
    </>
);

export default function Dashboard() {
    const { t } = useTranslation();
    usePageTitle(t('dashboard.pageTitle'));
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
        setCrumbs([{ label: t('dashboard.pageTitle') }]);
    }, [setCrumbs, t]);

    useEffect(() => {
        api.get('/dashboard')
            .then(({ data: response }) => setData(response))
            .catch(() => setError(t('dashboard.loadFailed')));
    }, [t]);

    useEffect(() => {
        api.get('/analytics/overview', { params: { from: range.from, to: range.to } })
            .then(({ data: response }) => setAnalytics(response))
            .catch(() => setAnalytics(null));
         
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
    const greeting = t(`dashboard.greeting.${hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening'}`);

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!data) {
        return (
            <div className="space-y-6" aria-busy="true" aria-label={t('dashboard.loading')}>
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
            label: t('dashboard.heroes.myOpen.label'),
            value: data.counts.my_open,
            hint: data.scope === 'all' ? t('dashboard.heroes.myOpen.hintAll') : t('dashboard.heroes.myOpen.hintMine'),
            icon: ClipboardText,
        },
        {
            label: t('dashboard.heroes.overdue.label'),
            value: data.counts.my_overdue,
            hint: t('dashboard.heroes.overdue.hint'),
            icon: ClockCountdown,
            danger: true,
        },
    ];
    const stats = [
        { label: t('dashboard.stats.dueSoon.label'), value: data.counts.my_due_soon, hint: t('dashboard.stats.dueSoon.hint'), icon: CalendarBlank },
        { label: t('dashboard.stats.inProgress.label'), value: data.counts.in_progress, hint: t('dashboard.stats.inProgress.hint'), icon: Lightning },
        { label: t('dashboard.stats.allOpen.label'), value: data.counts.open, hint: t('dashboard.stats.allOpen.hint'), icon: Stack },
        { label: t('dashboard.stats.completed.label'), value: data.counts.done, hint: t('dashboard.stats.completed.hint'), icon: CheckCircle },
    ];

    return (
        <div className="space-y-6">
            <div ref={heroRef}>
                <h2 data-hero-reveal className="text-2xl font-bold text-gray-900">
                    {greeting}, {user?.name?.split(' ')[0]}
                </h2>
                <p data-hero-reveal className="mt-1 text-sm text-gray-600">
                    {t('dashboard.subtitle', { open: data.counts.open, done: data.counts.done })}
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
                                <span className="block truncate text-xs text-gray-500">{stat.hint}</span>
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
                        <Card title={t('dashboard.charts.projectProgress.title')} subtitle={t('dashboard.charts.projectProgress.subtitle')}>
                            <ProjectProgressChart projects={analytics.projects_progress || []} mode={resolvedMode} onBrowse={() => navigate('/projects')} />
                        </Card>
                        <Card
                            title={t('dashboard.charts.hours.title')}
                            subtitle={t('dashboard.charts.hours.subtitle', { range: rangeLabel(preset, range), total: formatHours((analytics.work_logs?.total_minutes || 0) / 60, t) })}
                        >
                            <HoursLoggedChart daily={analytics.work_logs?.daily || []} mode={resolvedMode} onBrowse={() => navigate('/projects')} />
                        </Card>
                        <Card
                            title={t('dashboard.charts.created.title')}
                            subtitle={t('dashboard.charts.created.subtitle', { range: rangeLabel(preset, range), count: analytics.counts?.created_in_range ?? 0 })}
                        >
                            <TasksCreatedChart daily={analytics.tasks_created?.daily || []} mode={resolvedMode} onBrowse={() => navigate('/projects')} />
                        </Card>
                    </div>

                    {analytics.top_contributors?.length > 0 && (
                        <Card title={t('dashboard.contributors.title')} subtitle={t('dashboard.contributors.subtitle')}>
                            <ul className="divide-y divide-gray-100">
                                {analytics.top_contributors.map((c) => (
                                    <li key={c.user?.id} className="flex items-center justify-between py-2.5">
                                        <span className="flex items-center gap-3">
                                            <Avatar name={c.user?.name} photoUrl={c.user?.photo_url} />
                                            <span className="text-sm font-medium text-gray-800">{c.user?.name || t('dashboard.contributors.unknown')}</span>
                                        </span>
                                        <span className="text-sm text-gray-500">
                                            {formatHours(c.minutes / 60, t)} · {t('dashboard.contributors.logs', { count: c.logs_count })}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <TaskList tasks={data.my_overdue} title={t('dashboard.lists.overdue.title')} subtitle={t('dashboard.lists.overdue.subtitle')} emptyText={t('dashboard.lists.overdue.empty')} dateLabel={(task) => t('dashboard.dueLabels.overdue', { date: task.due_date })} />
                <TaskList tasks={data.my_due_soon} title={t('dashboard.lists.dueSoon.title')} subtitle={t('dashboard.lists.dueSoon.subtitle')} emptyText={t('dashboard.lists.dueSoon.empty')} dateLabel={(task) => t('dashboard.dueLabels.due', { date: task.due_date, project: task.project.name })} />

                <TaskList tasks={data.in_progress} title={t('dashboard.lists.inProgress.title')} subtitle={t('dashboard.lists.inProgress.subtitle')} emptyText={t('dashboard.lists.inProgress.empty')} dateLabel={recentLabel} />
                <TaskList tasks={data.my_open} title={t('dashboard.lists.recent.title')} subtitle={t('dashboard.lists.recent.subtitle')} emptyText={t('dashboard.lists.recent.empty')} dateLabel={() => t('dashboard.dueLabels.assigned')} />
            </div>
        </div>
    );
}