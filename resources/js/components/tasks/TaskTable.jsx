import { useTranslation } from 'react-i18next';

export default function TaskTable({ tasks, canEdit, onOpen }) {
    const { t } = useTranslation();
    if (tasks.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-gray-200 py-12 text-center text-sm text-gray-400">
                {t('tms.table.empty')}
            </div>
        );
    }

    return (
        <div className="overflow-x-auto rounded-xl border border-gray-200/70 shadow-sm">
            <table className="w-full text-left text-sm">
                <thead className="border-b border-gray-100 bg-gray-50/80 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th className="px-4 py-3 font-medium">{t('tms.table.thKey')}</th>
                        <th className="px-4 py-3 font-medium">{t('tms.table.thType')}</th>
                        <th className="px-4 py-3 font-medium">{t('tms.table.thTask')}</th>
                        <th className="px-4 py-3 font-medium">{t('tms.table.thStatus')}</th>
                        <th className="px-4 py-3 font-medium">{t('tms.table.thPriority')}</th>
                        <th className="px-4 py-3 font-medium">{t('tms.table.thPoints')}</th>
                        <th className="px-4 py-3 font-medium">{t('tms.table.thAssignee')}</th>
                        <th className="px-4 py-3 font-medium">{t('tms.table.thDue')}</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                    {tasks.map((task) => (
                        <tr
                            key={task.id}
                            onClick={() => canEdit && onOpen(task)}
                            className={`bg-white align-middle transition ${canEdit ? 'cursor-pointer hover:bg-[var(--accent-soft)]' : ''}`}
                        >
                            <td className="px-4 py-3 font-semibold text-gray-500">{task.key}</td>
                            <td className="px-4 py-3">
                                {task.issue_type ? (
                                    <span
                                        className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold"
                                        style={{
                                            backgroundColor: `${task.issue_type.color || '#2563eb'}18`,
                                            color: task.issue_type.color || '#2563eb',
                                        }}
                                    >
                                        {task.issue_type.name}
                                    </span>
                                ) : (
                                    <span className="text-xs text-gray-400">—</span>
                                )}
                            </td>
                            <td className="px-4 py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`font-medium text-gray-900 ${task.completed_at ? 'line-through opacity-60' : ''}`}>
                                        {task.title}
                                    </span>
                                    {task.subtasks_count > 0 && (
                                        <span className="text-[11px] text-gray-400">{t('tms.card.subtasksShort', { count: task.subtasks_count })}</span>
                                    )}
                                </div>
                                {(task.version || task.components?.length > 0 || task.labels?.length > 0) && (
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {task.version && (
                                            <span className="rounded bg-purple-50 px-1.5 py-0.5 text-[10px] font-medium text-purple-700">
                                                {task.version.name}
                                            </span>
                                        )}
                                        {task.components?.map((c) => (
                                            <span key={c.id} className="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">
                                                {c.name}
                                            </span>
                                        ))}
                                        {task.labels?.map((label) => (
                                            <span
                                                key={label.id}
                                                className="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                                style={{
                                                    backgroundColor: `${label.color || '#e2e8f0'}22`,
                                                    color: label.color || '#475569',
                                                }}
                                            >
                                                {label.name}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </td>
                            <td className="px-4 py-3">
                                <span className="inline-flex items-center gap-1.5 text-xs text-gray-600">
                                    <span className="h-2 w-2 rounded-full" style={{ backgroundColor: task.status?.color || '#cbd5e1' }} />
                                    {task.status?.name}
                                </span>
                            </td>
                            <td className="px-4 py-3 text-xs text-gray-600">{task.priority?.name ?? '—'}</td>
                            <td className="px-4 py-3 text-xs font-semibold text-gray-600">
                                {task.story_points != null ? `${task.story_points}` : '—'}
                            </td>
                            <td className="px-4 py-3 text-xs text-gray-600">{task.assignee?.name ?? '—'}</td>
                            <td className="px-4 py-3 text-xs text-gray-500">{task.due_date ?? '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}