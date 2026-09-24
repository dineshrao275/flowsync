import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

const ROLE_LABELS = {
    lead: 'Lead',
    developer: 'Developer',
    viewer: 'Viewer',
};

export default function Projects() {
    usePageTitle('Projects');
    const setCrumbs = useSetCrumbs();
    const [projects, setProjects] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'Projects' }]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function load() {
        try {
            const { data } = await api.get('/projects');
            setProjects(data.projects);
        } catch {
            setError('Unable to load projects.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    const grouped = projects.reduce((acc, project) => {
        const key = project.workspace?.name || `Workspace ${project.workspace_id}`;
        (acc[key] ||= { workspaceId: project.workspace_id, projects: [] }).projects.push(project);
        return acc;
    }, {});

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Projects</h2>
                <p className="mt-1 text-sm text-gray-500">All projects you can access across your workspaces.</p>
            </div>

            {error && <Alert>{error}</Alert>}

            {projects.length === 0 ? (
                <Card>
                    <p className="text-sm text-gray-500">No projects yet. Create one from a workspace.</p>
                </Card>
            ) : (
                Object.entries(grouped).map(([workspaceName, { workspaceId, projects: wsProjects }]) => (
                    <Card key={workspaceName} title={workspaceName}>
                        <ul className="divide-y divide-gray-100">
                            {wsProjects.map((project) => (
                                <li key={project.id}>
                                    <Link
                                        to={`/projects/${project.id}`}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3 transition hover:bg-gray-50"
                                    >
                                        <div className="flex items-center gap-3">
                                            <span
                                                className="flex h-10 w-10 items-center justify-center rounded-lg text-xs font-bold text-white"
                                                style={{ backgroundColor: 'var(--accent)' }}
                                            >
                                                {project.key}
                                            </span>
                                            <div>
                                                <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                                                    {project.name}
                                                    {project.my_role && (
                                                        <Badge>{ROLE_LABELS[project.my_role]?.toLowerCase() || project.my_role}</Badge>
                                                    )}
                                                    {project.archived_at && <Badge>archived</Badge>}
                                                </p>
                                                <p className="mt-0.5 line-clamp-1 text-xs text-gray-400">
                                                    {project.description || 'No description'}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-4 text-xs text-gray-400">
                                            <span>{project.tasks_count} tasks</span>
                                            <span>{project.members_count} members</span>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                ))
            )}
        </div>
    );
}