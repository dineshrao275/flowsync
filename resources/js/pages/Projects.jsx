import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../services/api';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
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
                <Table>
                    <thead>
                        <tr>
                            <Th>Project</Th>
                            <Th>Description</Th>
                            <Th>Your role</Th>
                            <Th align="right">Tasks</Th>
                            <Th align="right">Members</Th>
                        </tr>
                    </thead>
                    <tbody>
                        <TableEmpty colSpan={5}>No projects yet. Create one from a workspace.</TableEmpty>
                    </tbody>
                </Table>
            ) : (
                Object.entries(grouped).map(([workspaceName, { projects: wsProjects }]) => (
                    <div key={workspaceName} className="space-y-3">
                        <h3 className="text-sm font-semibold text-gray-900">{workspaceName}</h3>
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Project</Th>
                                    <Th>Description</Th>
                                    <Th>Your role</Th>
                                    <Th align="right">Tasks</Th>
                                    <Th align="right">Members</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {wsProjects.map((project, index) => (
                                    <tr
                                        key={project.id}
                                        className="animate-fade-in transition-colors duration-150 hover:bg-gray-50"
                                        style={{ animationDelay: `${index * 30}ms` }}
                                    >
                                        <Td>
                                            <Link
                                                to={`/projects/${project.id}`}
                                                className="flex items-center gap-3 font-medium text-gray-900 hover:text-indigo-600"
                                            >
                                                <span
                                                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white"
                                                    style={{ backgroundColor: 'var(--accent)' }}
                                                >
                                                    {project.key}
                                                </span>
                                                <span className="flex min-w-0 flex-wrap items-center gap-2">
                                                    <span className="truncate">{project.name}</span>
                                                    {project.archived_at && <Badge>archived</Badge>}
                                                </span>
                                            </Link>
                                        </Td>
                                        <Td>
                                            <span className="block max-w-md truncate text-gray-500">
                                                {project.description || 'No description'}
                                            </span>
                                        </Td>
                                        <Td>
                                            {project.my_role ? (
                                                <Badge>
                                                    {ROLE_LABELS[project.my_role]?.toLowerCase() || project.my_role}
                                                </Badge>
                                            ) : (
                                                <span className="text-gray-400">—</span>
                                            )}
                                        </Td>
                                        <Td align="right" className="tabular-nums">
                                            {project.tasks_count}
                                        </Td>
                                        <Td align="right" className="tabular-nums">
                                            {project.members_count}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                ))
            )}
        </div>
    );
}