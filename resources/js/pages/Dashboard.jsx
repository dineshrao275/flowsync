import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../services/api';
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { useAuth } from '../context/AuthContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

function TaskRow({ task, dateLabel = null }) {
    return (
        <Link
            to={`/projects/${task.project.id}?tab=tasks`}
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

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <TaskList tasks={data.my_overdue} title="Overdue" subtitle="Open tasks past their due date" emptyText="Nothing overdue." dateLabel={overdueLabel} />
                <TaskList tasks={data.my_due_soon} title="Due soon" subtitle="Next seven days, assigned to you" emptyText="Nothing due in the next week." dateLabel={dueSoonLabel} />

                <TaskList tasks={data.in_progress} title="In progress" subtitle="Currently being worked on" emptyText="No tasks in progress." dateLabel={recentLabel} />
                <TaskList tasks={data.my_open} title="Recently updated" subtitle="Your open tasks, newest activity first" emptyText="No recent changes." dateLabel={() => 'Assigned to you'} />
            </div>
        </div>
    );
}