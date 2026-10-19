import { useTranslation } from 'react-i18next';
import ProgressBar from '../ui/ProgressBar';
import Card from '../../components/ui/Card';

export default function ProjectOverviewTab({ lead, members, project, statuses }) {
    const { t } = useTranslation();

    return (
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="space-y-5 lg:col-span-2">
                        <Card title={t('tms.project.detailsTitle')} subtitle={t('tms.project.detailsSubtitle', { id: project.workspace_id })}>
                            <dl className="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-400">{t('tms.project.key')}</dt>
                                    <dd className="mt-1 font-semibold text-gray-900">{project.key}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-400">{t('tms.project.lead')}</dt>
                                    <dd className="mt-1 text-gray-700">{lead?.name ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-400">{t('tms.project.startDate')}</dt>
                                    <dd className="mt-1 text-gray-700">{project.start_date ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-400">{t('tms.project.dueDate')}</dt>
                                    <dd className="mt-1 text-gray-700">{project.due_date ?? '—'}</dd>
                                </div>
                            </dl>
                        </Card>
                    </div>
                    <div className="space-y-5">
                        <Card title={t('tms.project.workflowTitle')}>
                            {statuses.length === 0 ? (
                                <p className="text-sm text-gray-500">{t('tms.project.noStatuses')}</p>
                            ) : (
                                <ol className="space-y-2">
                                    {statuses.map((s) => (
                                        <li
                                            key={s.id}
                                            className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                                        >
                                            <span className="flex items-center gap-2 font-medium text-gray-700">
                                                <span className="h-3 w-3 rounded-full" style={{ backgroundColor: s.color || '#cbd5e1' }} />
                                                {s.name}
                                            </span>
                                            <span className="text-xs text-gray-400">{t(`tms.project.categories.${s.category}`)}</span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                            {(project.tasks_count ?? 0) > 0 && (
                                <div className="mt-4">
                                    <div className="mb-1 flex justify-between text-xs text-gray-500">
                                        <span>{t('tms.project.progress')}</span>
                                        <span>{t('tms.project.progressDone', { done: project.done_tasks_count ?? 0, total: project.tasks_count })}</span>
                                    </div>
                                    <ProgressBar value={project.done_tasks_count ?? 0} max={project.tasks_count} label={t('tms.project.progress')} showPercent />
                                </div>
                            )}
                            <div className="mt-4 grid grid-cols-3 gap-3 text-center">
                                <div className="rounded-lg border border-gray-100 px-3 py-2">
                                    <p className="text-xl font-bold text-gray-900">{members.length}</p>
                                    <p className="text-xs text-gray-500">{t('tms.project.statMembers')}</p>
                                </div>
                                <div className="rounded-lg border border-gray-100 px-3 py-2">
                                    <p className="text-xl font-bold text-gray-900">{project.tasks_count ?? 0}</p>
                                    <p className="text-xs text-gray-500">{t('tms.project.statTasks')}</p>
                                </div>
                                <div className="rounded-lg border border-gray-100 px-3 py-2">
                                    <p className="text-xl font-bold text-gray-900">{statuses.length}</p>
                                    <p className="text-xs text-gray-500">{t('tms.project.statStatuses')}</p>
                                </div>
                            </div>
                        </Card>
                    </div>
                </div>
    );
}
