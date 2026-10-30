import { useSortable } from '@dnd-kit/sortable';
import Avatar from '../ui/Avatar';
import UserTooltip from '../ui/UserTooltip';
import ProgressBar from '../ui/ProgressBar';
import TaskBadge from '../ui/TaskBadge';
import { CSS } from '@dnd-kit/utilities';
import { useTranslation } from 'react-i18next';
import { formatMinutes } from '../../utils/time';
import { CalendarBlank, ChatCircle, CheckSquare, Clock } from '@phosphor-icons/react';

const PRIORITY_DOT = {
    highest: '#ef4444',
    high: '#f97316',
    medium: '#f59e0b',
    low: '#10b981',
    lowest: '#94a3b8',
};

function formatDue(date, t) {
    if (!date) return null;
    const d = new Date(`${date}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const diff = Math.round((d - today) / 86400000);

    if (diff < 0) {
        return {
            label: t('tms.card.dueOverdueShort', { defaultValue: 'Overdue' }),
            fullTitle: t('tms.card.dueOverdue', { date }),
            tone: 'danger',
        };
    }
    if (diff === 0) {
        return {
            label: t('tms.card.dueTodayShort', { defaultValue: 'Today' }),
            fullTitle: t('tms.card.dueToday', { date }),
            tone: 'warning',
        };
    }
    if (diff === 1) {
        return {
            label: t('tms.card.dueTomorrowShort', { defaultValue: 'Tomorrow' }),
            fullTitle: t('tms.card.dueTomorrow', { date }),
            tone: 'warning',
        };
    }

    const month = d.toLocaleString('en', { month: 'short' });
    const day = d.getDate();
    return {
        label: `${month} ${day}`,
        fullTitle: date,
        tone: 'neutral',
    };
}

export const CARD_CLASS =
    'group min-w-0 cursor-pointer overflow-hidden rounded-lg border border-gray-200 bg-white px-3 py-2.5 shadow-xs transition hover:border-[var(--accent-ring)] hover:shadow-md active:z-10 dark:border-slate-800 dark:bg-slate-900';

/** The card's content, shared by the sortable card and the drag overlay. */
export function TaskCardBody({ task, compact = false }) {
    const { t } = useTranslation();
    const due = formatDue(task.due_date, t);

    return (
        <>
            <div className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                    {task.issue_type && (
                        <span
                            className="inline-flex max-w-[9rem] items-center truncate rounded px-1.5 py-0.5 text-xs font-semibold"
                            style={{
                                backgroundColor: `${task.issue_type.color || '#2563eb'}18`,
                                color: task.issue_type.color || '#2563eb',
                            }}
                            title={task.issue_type.name}
                        >
                            {task.issue_type.name}
                        </span>
                    )}
                    <TaskBadge taskKey={task.key} taskId={task.id} projectId={task.project_id} className="text-[11px] py-0 px-1.5" />
                </div>
                <div className="flex items-center gap-2">
                    {task.story_points != null && (
                        <span
                            className="rounded bg-[var(--accent-soft)] px-1.5 py-0.5 text-xs font-semibold text-[var(--accent-soft-text)]"
                            title={t('tms.card.storyPointsTitle', { count: task.story_points })}
                        >
                            {t('tms.card.storyPoints', { count: task.story_points })}
                        </span>
                    )}
                    {task.priority && (
                        <span className="flex items-center gap-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                            <span
                                className="h-2 w-2 rounded-full shrink-0"
                                style={{ backgroundColor: PRIORITY_DOT[task.priority.slug] || task.priority.color || '#94a3b8' }}
                            />
                            {task.priority.name}
                        </span>
                    )}
                </div>
            </div>
            <p
                title={task.title}
                className={`mt-1.5 line-clamp-3 break-words text-sm font-medium leading-snug text-gray-900 dark:text-gray-100 [overflow-wrap:anywhere] ${task.completed_at ? 'line-through opacity-60' : ''}`}
            >
                {task.title}
            </p>
            {!compact && (task.version || task.components?.length > 0) && (
                <div className="mt-1.5 flex flex-wrap gap-1">
                    {task.version && (
                        <span className="max-w-full truncate rounded bg-purple-50 px-1.5 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-950/40 dark:text-purple-300" title={task.version.name}>
                            {task.version.name}
                        </span>
                    )}
                    {task.components?.map((c) => (
                        <span key={c.id} className="max-w-full truncate rounded bg-blue-50 px-1.5 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-300" title={c.name}>
                            {c.name}
                        </span>
                    ))}
                </div>
            )}
            {!compact && task.labels?.length > 0 && (
                <div className="mt-1.5 flex flex-wrap gap-1">
                    {task.labels.map((label) => (
                        <span
                            key={label.id}
                            className="max-w-full truncate rounded-full px-2 py-0.5 text-xs font-medium"
                            title={label.name}
                            style={{ backgroundColor: `${label.color || '#e2e8f0'}22`, color: label.color || '#475569' }}
                        >
                            {label.name}
                        </span>
                    ))}
                </div>
            )}
            {task.subtasks_count > 0 && (
                <ProgressBar
                    className="mt-2"
                    value={task.subtasks_done_count ?? 0}
                    max={task.subtasks_count}
                    label={t('tms.card.subtasksProgress', { done: task.subtasks_done_count ?? 0, total: task.subtasks_count })}
                />
            )}
            <div className="mt-2.5 flex items-center justify-between gap-2 border-t border-gray-100/90 pt-2 dark:border-slate-800">
                <div className="flex min-w-0 flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    {task.subtasks_count > 0 && (
                        <span
                            className="inline-flex items-center gap-1 font-medium"
                            title={t('tms.card.subtasksProgress', { done: task.subtasks_done_count ?? 0, total: task.subtasks_count })}
                        >
                            <CheckSquare size={13} weight="bold" className="text-gray-400 shrink-0" />
                            <span>{task.subtasks_done_count != null ? `${task.subtasks_done_count}/${task.subtasks_count}` : task.subtasks_count}</span>
                        </span>
                    )}
                    {task.comments_count > 0 && (
                        <span
                            className="inline-flex items-center gap-1 font-medium"
                            title={t('tms.card.commentsCount', { count: task.comments_count })}
                        >
                            <ChatCircle size={13} weight="bold" className="text-gray-400 shrink-0" />
                            <span>{task.comments_count}</span>
                        </span>
                    )}
                    {task.estimate_minutes != null && task.estimate_minutes > 0 && (
                        <span
                            className="inline-flex items-center gap-1 font-medium"
                            title={t('tms.card.estimateShort', { value: formatMinutes(task.estimate_minutes) })}
                        >
                            <Clock size={13} className="text-gray-400 shrink-0" />
                            <span>{formatMinutes(task.estimate_minutes)}</span>
                        </span>
                    )}
                    {due && (
                        <span
                            title={due.fullTitle}
                            className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.68rem] font-semibold border ${
                                due.tone === 'danger'
                                    ? 'bg-rose-50 text-rose-600 border-rose-200/90 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-900'
                                    : due.tone === 'warning'
                                    ? 'bg-amber-50 text-amber-700 border-amber-200/90 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-900'
                                    : 'bg-slate-50 text-slate-600 border-slate-200/90 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700'
                            }`}
                        >
                            <CalendarBlank size={11} weight="bold" className="shrink-0" />
                            <span>{due.label}</span>
                        </span>
                    )}
                </div>
                <div className="shrink-0 pl-1">
                    {task.assignee ? (
                        <UserTooltip user={task.assignee} name={task.assignee.name}>
                            <Avatar name={task.assignee.name} photoUrl={task.assignee.photo_url} size="xs" />
                        </UserTooltip>
                    ) : (
                        <span className="inline-block h-5 w-5 rounded-full border border-dashed border-gray-300 dark:border-slate-700" title="Unassigned" />
                    )}
                </div>
            </div>
        </>
    );
}

export default function TaskCard({ task, onClick, disabled, compact = false }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id: task.id,
        disabled: Boolean(disabled),
    });

    return (
        <div
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
                opacity: isDragging ? 0.4 : 1,
            }}
            {...attributes}
            {...listeners}
            onClick={onClick}
            className={compact ? `${CARD_CLASS} !px-2.5 !py-1.5` : CARD_CLASS}
        >
            <TaskCardBody task={task} compact={compact} />
        </div>
    );
}
