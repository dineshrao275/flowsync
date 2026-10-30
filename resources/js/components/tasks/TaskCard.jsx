import { useSortable } from '@dnd-kit/sortable';
import Avatar from '../ui/Avatar';
import UserTooltip from '../ui/UserTooltip';
import ProgressBar from '../ui/ProgressBar';
import { CSS } from '@dnd-kit/utilities';
import { useTranslation } from 'react-i18next';
import { formatMinutes } from '../../utils/time';

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
    const diff = (d - today) / 86400000;
    if (d < today) return { label: t('tms.card.dueOverdue', { date }), className: 'text-red-600' };
    if (diff === 0) return { label: t('tms.card.dueToday', { date }), className: 'text-red-600' };
    if (diff === 1) return { label: t('tms.card.dueTomorrow', { date }), className: 'text-amber-600' };
    return { label: date, className: 'text-gray-500' };
}

export const CARD_CLASS =
    'group min-w-0 cursor-pointer overflow-hidden rounded-lg border border-gray-200 bg-white px-3 py-2.5 shadow-sm transition hover:border-[var(--accent-ring)] hover:shadow-md active:z-10';

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
                    <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">{task.key}</span>
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
                        <span className="flex items-center gap-1 text-xs font-medium text-gray-500">
                            <span
                                className="h-2 w-2 rounded-full"
                                style={{ backgroundColor: PRIORITY_DOT[task.priority.slug] || task.priority.color || '#94a3b8' }}
                            />
                            {task.priority.name}
                        </span>
                    )}
                </div>
            </div>
            <p
                title={task.title}
                className={`mt-1 line-clamp-3 break-words text-sm font-medium leading-snug text-gray-900 [overflow-wrap:anywhere] ${task.completed_at ? 'line-through opacity-60' : ''}`}
            >
                {task.title}
            </p>
            {!compact && (task.version || task.components?.length > 0) && (
                <div className="mt-1.5 flex flex-wrap gap-1">
                    {task.version && (
                        <span className="max-w-full truncate rounded bg-purple-50 px-1.5 py-0.5 text-xs font-medium text-purple-700" title={task.version.name}>
                            {task.version.name}
                        </span>
                    )}
                    {task.components?.map((c) => (
                        <span key={c.id} className="max-w-full truncate rounded bg-blue-50 px-1.5 py-0.5 text-xs font-medium text-blue-700" title={c.name}>
                            {c.name}
                        </span>
                    ))}
                </div>
            )}
            {!compact && task.labels?.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
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
            <div className="mt-2 flex items-center justify-between">
                <div className="flex items-center gap-2 text-xs text-gray-400">
                    {task.subtasks_count > 0 && (
                        <span className="flex items-center gap-0.5" title={t('tms.card.subtasks')}>
                            {t('tms.card.subtasksShort', { count: task.subtasks_count })}
                        </span>
                    )}
                    {task.comments_count > 0 && <span title={t('tms.card.comments')}>{t('tms.card.commentsCount', { count: task.comments_count })}</span>}
                    {task.estimate_minutes != null && task.estimate_minutes > 0 && (
                        <span title={t('tms.card.estimate')}>{t('tms.card.estimateShort', { value: formatMinutes(task.estimate_minutes) })}</span>
                    )}
                    {due && <span className={due.className}>{due.label}</span>}
                </div>
                {task.assignee ? (
                    <UserTooltip user={task.assignee} name={task.assignee.name}>
                        <Avatar name={task.assignee.name} photoUrl={task.assignee.photo_url} size="xs" />
                    </UserTooltip>
                ) : (
                    <span className="h-5 w-5 rounded-full border border-dashed border-gray-300" />
                )}
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
