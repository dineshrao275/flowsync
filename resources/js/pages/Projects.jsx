import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import { projectRoleLabel } from '../utils/roles';

export default function Projects() {
    const { t } = useTranslation();
    usePageTitle(t('tms.projectsList.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const [projects, setProjects] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('tms.projectsList.pageTitle') }]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function load() {
        try {
            const { data } = await api.get('/projects');
            setProjects(data.projects);
        } catch {
            setError(t('tms.projectsList.loadFailed'));
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
        const key = project.workspace?.name || t('tms.projectsList.workspaceFallback', { id: project.workspace_id });
        (acc[key] ||= { workspaceId: project.workspace_id, projects: [] }).projects.push(project);
        return acc;
    }, {});

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('tms.projectsList.pageTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('tms.projectsList.subtitle')}</p>
            </div>

            {error && <Alert>{error}</Alert>}

            {projects.length === 0 ? (
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('tms.projectsList.thProject')}</Th>
                            <Th>{t('tms.projectsList.thDescription')}</Th>
                            <Th>{t('tms.projectsList.thRole')}</Th>
                            <Th align="right">{t('tms.projectsList.thTasks')}</Th>
                            <Th align="right">{t('tms.projectsList.thMembers')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        <TableEmpty colSpan={5}>{t('tms.projectsList.empty')}</TableEmpty>
                    </tbody>
                </Table>
            ) : (
                Object.entries(grouped).map(([workspaceName, { projects: wsProjects }]) => (
                    <div key={workspaceName} className="space-y-3">
                        <h3 className="text-sm font-semibold text-gray-900">{workspaceName}</h3>
                        <Table>
                            <thead>
                                <tr>
                                    <Th>{t('tms.projectsList.thProject')}</Th>
                                    <Th>{t('tms.projectsList.thDescription')}</Th>
                                    <Th>{t('tms.projectsList.thRole')}</Th>
                                    <Th align="right">{t('tms.projectsList.thTasks')}</Th>
                                    <Th align="right">{t('tms.projectsList.thMembers')}</Th>
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
                                                className="flex items-center gap-3 font-medium text-gray-900 hover:text-[var(--accent-soft-text)]"
                                            >
                                                <span
                                                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white"
                                                    style={{ backgroundColor: 'var(--accent)' }}
                                                >
                                                    {project.key}
                                                </span>
                                                 <span className="flex min-w-0 flex-wrap items-center gap-2">
                                                    <span className="truncate">{project.name}</span>
                                                    {project.archived_at && <Badge>{t('tms.projectsList.archived')}</Badge>}
                                                </span>
                                            </Link>
                                        </Td>
                                        <Td>
                                            <span className="block max-w-md truncate text-gray-500">
                                                {project.description || t('tms.projectsList.noDescription')}
                                            </span>
                                        </Td>
                                        <Td>
                                            {project.my_role ? (
                                                <Badge>
                                                    {projectRoleLabel(project.my_role)}
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