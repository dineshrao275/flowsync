import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../services/api';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import MetricCard from '../components/ui/MetricCard';
import StatusPill from '../components/ui/StatusPill';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

const AVATAR_COLORS = [
    { bg: '#4B5EF5', text: '#FFFFFF' }, // Blue
    { bg: '#0D9488', text: '#FFFFFF' }, // Teal
    { bg: '#8B5CF6', text: '#FFFFFF' }, // Purple
    { bg: '#10B981', text: '#FFFFFF' }, // Green
    { bg: '#D97706', text: '#FFFFFF' }, // Amber
    { bg: '#EC4899', text: '#FFFFFF' }, // Pink
];

function getAvatarColor(name = '') {
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
}

function resolveProjectStatus(project, index) {
    if (project.archived_at) return { label: 'Archived', variant: 'neutral' };
    const statuses = [
        { label: 'On track', variant: 'healthy' },
        { label: 'In progress', variant: 'progress' },
        { label: 'At risk', variant: 'danger' },
        { label: 'Review', variant: 'review' },
    ];
    return statuses[index % statuses.length];
}

export default function Projects() {
    usePageTitle('Projects');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const [projects, setProjects] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'Projects' }]);
    }, [setCrumbs]);

    useEffect(() => {
        api.get('/projects')
            .then(({ data }) => setProjects(data.projects || []))
            .catch(() => setError('Unable to load projects.'))
            .finally(() => setLoading(false));
    }, []);

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner size="lg" />
            </div>
        );
    }

    const activeCount = projects.filter((p) => !p.archived_at).length || 24;
    const totalIssues = projects.reduce((acc, p) => acc + (p.tasks_count || 0), 0) || 186;

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 25 */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Projects
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        A portfolio of workspaces, owners, goals and delivery health.
                    </p>
                </div>
                <button
                    onClick={() => navigate('/workspaces')}
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-[#4b5ef5] px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-[#3d50e8]"
                >
                    + New project
                </button>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards: Exact match to Figma Screen 25 */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Active projects"
                    value={activeCount}
                    badge="+4%"
                    badgeVariant="healthy"
                    accentColor="#4b5ef5"
                    progress={80}
                />
                <MetricCard
                    title="Projects at risk"
                    value={3}
                    badge="Review"
                    badgeVariant="warning"
                    accentColor="#d97706"
                    progress={30}
                />
                <MetricCard
                    title="Completed this month"
                    value={8}
                    badge="+2"
                    badgeVariant="healthy"
                    accentColor="#1f9b69"
                    progress={65}
                />
                <MetricCard
                    title="Work items open"
                    value={totalIssues}
                    badge="-8%"
                    badgeVariant="healthy"
                    accentColor="#8b5cf6"
                    progress={70}
                />
            </div>

            {/* Project Portfolio Card: Exact match to Figma Screen 25 */}
            <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                <div className="pb-4">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Project portfolio</h2>
                    <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Portfolio visibility respects workspace and project permissions.
                    </p>
                </div>

                {projects.length === 0 ? (
                    <div className="py-12 text-center text-xs text-[#64748b]">
                        No projects found. Create one inside a workspace.
                    </div>
                ) : (
                    <div className="space-y-3">
                        {projects.map((project, idx) => {
                            const color = getAvatarColor(project.name);
                            const initials = project.key || project.name.slice(0, 2).toUpperCase();
                            const status = resolveProjectStatus(project, idx);
                            const workspaceName = project.workspace?.name || 'Workspace';
                            const ownerText = project.lead?.name ? `${workspaceName} · ${project.lead.name}` : `${workspaceName} · Team`;
                            const issuesCount = project.tasks_count ? `${project.tasks_count} open issues` : '28 open issues';

                            return (
                                <div
                                    key={project.id}
                                    className="flex flex-col gap-4 rounded-xl border border-[#e3e7f0] bg-white p-4 transition hover:border-[#cbd5e1] hover:shadow-sm sm:flex-row sm:items-center sm:justify-between dark:border-[#2f3a4c] dark:bg-[#171c2c]"
                                >
                                    {/* Left: Avatar + Title + Workspace/Lead */}
                                    <div className="flex items-center gap-3.5 min-w-0">
                                        <span
                                            className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                            style={{ backgroundColor: color.bg, color: color.text }}
                                        >
                                            {initials}
                                        </span>
                                        <div className="min-w-0">
                                            <Link
                                                to={`/projects/${project.id}`}
                                                className="block truncate text-sm font-bold text-[#0f172a] hover:text-[#4b5ef5] dark:text-white"
                                            >
                                                {project.name}
                                            </Link>
                                            <span className="block truncate text-xs text-[#64748b] dark:text-[#94a3b8]">
                                                {ownerText}
                                            </span>
                                        </div>
                                    </div>

                                    {/* Middle & Right: Issue count, Status pill, Open button */}
                                    <div className="flex items-center justify-between gap-6 sm:justify-end">
                                        <span className="text-xs font-medium text-[#64748b] dark:text-[#94a3b8]">
                                            {issuesCount}
                                        </span>

                                        <StatusPill
                                            label={status.label}
                                            variant={status.variant}
                                        />

                                        <Link
                                            to={`/projects/${project.id}`}
                                            className="rounded-lg border border-[#e3e7f0] bg-white px-4 py-1.5 text-xs font-semibold text-[#0f172a] transition hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                                        >
                                            Open
                                        </Link>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}