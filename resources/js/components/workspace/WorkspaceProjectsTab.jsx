import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import Card from '../ui/Card';
import Badge from '../ui/Badge';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import ProgressBar from '../ui/ProgressBar';
import RichTextEditor from '../ui/RichTextEditor';
import { stripRichText } from '../ui/RichText';
import { fieldClass } from '../ui/fieldStyles';
import { projectRoleLabel } from '../../utils/roles';

export function WorkspaceProjectsTab({
    workspace,
    projects,
    canCreateProject,
    archived,
    creatingProject,
    setCreatingProject,
    createProject,
    projectForm,
    setProjectForm,
    formErrors,
    templates,
    savingProject,
}) {
    const { t } = useTranslation();

    return (
        <div className="space-y-5">
            <Card
                title={t('tms.workspaceDetail.projectsIn', { name: workspace.name })}
                actions={
                    canCreateProject &&
                    !archived && (
                        <Button size="sm" variant="secondary" onClick={() => setCreatingProject((open) => !open)}>
                            {t('tms.workspaceDetail.newProject')}
                        </Button>
                    )
                }
            >
                {creatingProject && (
                    <form onSubmit={createProject} className="mb-5 space-y-4 rounded-lg border border-gray-200 p-4">
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input
                                label={t('tms.workspaceDetail.projectName')}
                                name="project-name"
                                placeholder={t('tms.workspaceDetail.projectNamePlaceholder')}
                                value={projectForm.name}
                                onChange={(e) => setProjectForm((f) => ({ ...f, name: e.target.value }))}
                                error={formErrors.name}
                                required
                            />
                            <Input
                                label={t('tms.workspaceDetail.projectKey')}
                                name="project-key"
                                placeholder={t('tms.workspaceDetail.projectKeyPlaceholder')}
                                value={projectForm.key}
                                onChange={(e) => setProjectForm((f) => ({ ...f, key: e.target.value }))}
                                error={formErrors.key}
                            />
                        </div>
                        {!projectForm.template_id && (
                            <div>
                                <label htmlFor="project-board-type" className="mb-1.5 block text-sm font-medium text-gray-700">
                                    {t('tms.workspaceDetail.boardType')}
                                </label>
                                <select
                                    id="project-board-type"
                                    className={fieldClass}
                                    value={projectForm.board_type}
                                    onChange={(e) => setProjectForm((f) => ({ ...f, board_type: e.target.value }))}
                                >
                                    {['kanban', 'scrum', 'basic'].map((bt) => (
                                        <option key={bt} value={bt}>
                                            {t(`tms.workspaceDetail.boardTypes.${bt}`)}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-gray-500">
                                    {t(`tms.workspaceDetail.boardTypeHint.${projectForm.board_type}`)}
                                </p>
                            </div>
                        )}
                        {templates.length > 0 && (
                            <div>
                                <label htmlFor="project-template" className="mb-1.5 block text-sm font-medium text-gray-700">
                                    {t('tms.templates.startFrom')}
                                </label>
                                <select
                                    id="project-template"
                                    className={fieldClass}
                                    value={projectForm.template_id}
                                    onChange={(e) => setProjectForm((f) => ({ ...f, template_id: e.target.value }))}
                                >
                                    <option value="">{t('tms.templates.blank')}</option>
                                    {templates.map((tpl) => (
                                        <option key={tpl.id} value={tpl.id}>
                                            {tpl.name} — {tpl.statuses.join(' → ')}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">
                                {t('tms.workspaceDetail.projectDesc')}
                            </label>
                            <RichTextEditor
                                value={projectForm.description}
                                onChange={(html) => setProjectForm((f) => ({ ...f, description: html }))}
                                placeholder={t('tms.workspaceDetail.projectDescPlaceholder')}
                                error={formErrors.description}
                            />
                        </div>
                        <div className="flex gap-2 pt-1">
                            <Button type="button" variant="secondary" onClick={() => setCreatingProject(false)}>
                                {t('tms.workspaceDetail.cancel')}
                            </Button>
                            <Button type="submit" loading={savingProject}>
                                {t('tms.workspaceDetail.createProject')}
                            </Button>
                        </div>
                    </form>
                )}

                {projects.length === 0 ? (
                    <p className="text-sm text-gray-500">
                        {workspace.my_role === 'member' && !canCreateProject
                            ? t('tms.workspaceDetail.emptyProjectsMember')
                            : t('tms.workspaceDetail.emptyProjectsCta')}
                    </p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <Link
                                    to={`/projects/${project.id}`}
                                    className="flex flex-wrap items-center justify-between gap-3 py-4 transition hover:bg-gray-50"
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
                                                    <Badge>{projectRoleLabel(project.my_role)}</Badge>
                                                )}
                                                {project.archived_at && <Badge>{t('tms.workspaceDetail.archivedBadge')}</Badge>}
                                            </p>
                                            <p className="mt-0.5 line-clamp-1 text-xs text-gray-400">
                                                {stripRichText(project.description) || t('tms.workspaceDetail.noDescription')}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-4 text-xs text-gray-400">
                                        <span>{t('tms.workspaceDetail.tasksCount', { count: project.tasks_count })}</span>
                                        <span>{t('tms.workspaceDetail.membersCount', { count: project.members_count })}</span>
                                        {project.tasks_count > 0 && (
                                            <ProgressBar
                                                className="w-28"
                                                value={project.done_tasks_count ?? 0}
                                                max={project.tasks_count}
                                                label={t('tms.project.progress')}
                                                showPercent
                                            />
                                        )}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}
