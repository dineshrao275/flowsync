import { useTranslation } from 'react-i18next';
import Card from '../../components/ui/Card';
import Badge from '../../components/ui/Badge';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import WorkflowRules from '../../components/tasks/WorkflowRules';
import SlaPanel from './SlaPanel';

export default function ProjectWorkflowTab({ addIssueType, addStatus, can, deleteIssueType, deleteStatus, formErrors, issueTypeErrors, issueTypeForm, issueTypes, moveStatus, projectId, savingIssueType, savingStatus, setIssueTypeForm, setStatusForm, statusForm, statuses, updateIssueType, updateStatus }) {
    const { t } = useTranslation();

    return (
                <div className="space-y-5">
                    <WorkflowRules projectId={projectId} />
                    <SlaPanel projectId={projectId} canManage />
                    <Card title={t('tms.project.newStatusTitle')} subtitle={t('tms.project.newStatusSubtitle')}>
                        <form onSubmit={addStatus} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div className="flex-1">
                                <Input
                                    label={t('tms.project.statusName')}
                                    name="status-name"
                                    placeholder={t('tms.project.statusNamePlaceholder')}
                                    value={statusForm.name}
                                    onChange={(e) => setStatusForm((f) => ({ ...f, name: e.target.value }))}
                                    error={formErrors.name}
                                    required
                                />
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.project.category')}</label>
                                <select
                                    className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-[var(--accent)] focus:outline-none"
                                    value={statusForm.category}
                                    onChange={(e) => setStatusForm((f) => ({ ...f, category: e.target.value }))}
                                >
                                    <option value="todo">{t('tms.project.categories.todo')}</option>
                                    <option value="in_progress">{t('tms.project.categories.in_progress')}</option>
                                    <option value="done">{t('tms.project.categories.done')}</option>
                                </select>
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.project.color')}</label>
                                <input
                                    type="color"
                                    className="h-[42px] w-16 rounded-lg border border-gray-300 bg-white p-1 shadow-sm focus:outline-none"
                                    value={statusForm.color}
                                    onChange={(e) => setStatusForm((f) => ({ ...f, color: e.target.value }))}
                                />
                            </div>
                            <div>
                                <Input
                                    label={t('tms.project.wip')}
                                    name="status-wip"
                                    type="number"
                                    min="1"
                                    placeholder={t('tms.project.wipPlaceholder')}
                                    value={statusForm.wip_limit ?? ''}
                                    onChange={(e) => setStatusForm((f) => ({ ...f, wip_limit: e.target.value === '' ? null : Number(e.target.value) }))}
                                />
                            </div>
                            <Button type="submit" loading={savingStatus}>
                                {t('tms.project.addStatus')}
                            </Button>
                        </form>
                    </Card>

                    <Card title={t('tms.project.workflowCardTitle')} subtitle={t('tms.project.workflowCardSubtitle')}>
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <ol className="space-y-2">
                            {statuses.map((status, index) => (
                                <li
                                    key={status.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2"
                                >
                                    <div className="flex items-center gap-2">
                                        <div className="flex flex-col">
                                            <button
                                                type="button"
                                                className="text-gray-400 transition hover:text-gray-700 disabled:opacity-30"
                                                disabled={index === 0}
                                                onClick={() => moveStatus(status, status.position - 1)}
                                                aria-label={t('tms.project.moveUp', { name: status.name })}
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                className="text-gray-400 transition hover:text-gray-700 disabled:opacity-30"
                                                disabled={index === statuses.length - 1}
                                                onClick={() => moveStatus(status, status.position + 1)}
                                                aria-label={t('tms.project.moveDown', { name: status.name })}
                                            >
                                                ↓
                                            </button>
                                        </div>
                                        <input
                                            type="text"
                                            className="w-40 rounded-lg border border-transparent bg-transparent px-2 py-1 text-sm font-semibold text-gray-800 focus:border-[var(--accent-ring)] focus:bg-white"
                                            defaultValue={status.name}
                                            onBlur={(e) => {
                                                if (e.target.value !== status.name && e.target.value.trim()) {
                                                    updateStatus(status, { name: e.target.value.trim() });
                                                }
                                            }}
                                        />
                                        <span className="h-3 w-3 rounded-full" style={{ backgroundColor: status.color || '#cbd5e1' }} />
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <select
                                            className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:outline-none"
                                            value={status.category}
                                            onChange={(e) => updateStatus(status, { category: e.target.value })}
                                        >
                                            <option value="todo">{t('tms.project.categories.todo')}</option>
                                            <option value="in_progress">{t('tms.project.categories.in_progress')}</option>
                                            <option value="done">{t('tms.project.categories.done')}</option>
                                        </select>
                                        <input
                                            type="number"
                                            min="1"
                                            placeholder={t('tms.project.wipShort')}
                                            title={t('tms.project.wipTitle')}
                                            className="w-16 rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:border-[var(--accent-ring)] focus:outline-none"
                                            defaultValue={status.wip_limit ?? ''}
                                            onBlur={(e) => {
                                                const next = e.target.value === '' ? null : Number(e.target.value);
                                                if ((next ?? '') !== (status.wip_limit ?? '')) {
                                                    updateStatus(status, { wip_limit: next });
                                                }
                                            }}
                                        />
                                        {status.is_default && <Badge>{t('tms.project.defaultBadge')}</Badge>}
                                        <Button size="sm" variant="ghost" onClick={() => deleteStatus(status)}>
                                            {t('tms.project.delete')}
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </Card>

                    <Card title={t('tms.project.typesTitle')} subtitle={t('tms.project.typesSubtitle')}>
                        {can('workspaces.manage') && (
                            <form onSubmit={addIssueType} className="mb-6 flex flex-col gap-3 rounded-lg border border-gray-100 bg-gray-50/50 p-4 sm:flex-row sm:items-end">
                                <div className="flex-1">
                                    <Input
                                        label={t('tms.project.typeName')}
                                        placeholder={t('tms.project.typeNamePlaceholder')}
                                        value={issueTypeForm.name}
                                        onChange={(e) => setIssueTypeForm((f) => ({ ...f, name: e.target.value }))}
                                        error={issueTypeErrors.name}
                                        required
                                    />
                                </div>
                                <div className="flex-1">
                                    <Input
                                        label={t('tms.project.typeDesc')}
                                        placeholder={t('tms.project.typeDescPlaceholder')}
                                        value={issueTypeForm.description}
                                        onChange={(e) => setIssueTypeForm((f) => ({ ...f, description: e.target.value }))}
                                    />
                                </div>
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.project.color')}</label>
                                    <input
                                        type="color"
                                        className="h-[42px] w-16 rounded-lg border border-gray-300 bg-white p-1 shadow-sm focus:outline-none"
                                        value={issueTypeForm.color}
                                        onChange={(e) => setIssueTypeForm((f) => ({ ...f, color: e.target.value }))}
                                    />
                                </div>
                                <div className="flex items-center gap-2 pb-2">
                                    <label className="flex items-center gap-1.5 text-xs text-gray-600 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300 accent-[var(--accent)]"
                                            checked={issueTypeForm.is_subtask}
                                            onChange={(e) => setIssueTypeForm((f) => ({ ...f, is_subtask: e.target.checked }))}
                                        />
                                        {t('tms.project.subtask')}
                                    </label>
                                </div>
                                <Button type="submit" loading={savingIssueType}>
                                    {t('tms.project.addType')}
                                </Button>
                            </form>
                        )}
                        {issueTypeErrors.form && <Alert>{issueTypeErrors.form}</Alert>}
                        <ul className="divide-y divide-gray-100">
                            {issueTypes.map((type) => (
                                <li key={type.id} className="flex items-center justify-between py-3">
                                    <div className="flex items-center gap-3">
                                        <span
                                            className="h-3.5 w-3.5 rounded-full"
                                            style={{ backgroundColor: type.color || '#2563eb' }}
                                        />
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium text-gray-900">{type.name}</span>
                                                <span className="text-xs text-gray-400">({type.slug})</span>
                                                {type.is_subtask && (
                                                    <Badge color="purple">{t('tms.project.subtaskBadge')}</Badge>
                                                )}
                                                {type.tasks_count != null && (
                                                    <span className="text-xs text-gray-400">{t('tms.project.tasksCount', { count: type.tasks_count })}</span>
                                                )}
                                            </div>
                                            {type.description && (
                                                <p className="text-xs text-gray-500">{type.description}</p>
                                            )}
                                        </div>
                                    </div>
                                    {can('workspaces.manage') && (
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                className="h-7 w-8 rounded border border-gray-200 bg-white p-0.5"
                                                defaultValue={type.color || '#2563eb'}
                                                onBlur={(e) => {
                                                    if (e.target.value !== type.color) {
                                                        updateIssueType(type, { color: e.target.value });
                                                    }
                                                }}
                                                title={t('tms.project.changeColor')}
                                            />
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => deleteIssueType(type)}
                                                disabled={type.tasks_count > 0}
                                                title={type.tasks_count > 0 ? t('tms.project.cannotDelete') : t('tms.project.delete')}
                                            >
                                                {t('tms.project.delete')}
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
    );
}
