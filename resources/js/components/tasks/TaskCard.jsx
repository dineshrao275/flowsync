import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { formatMinutes } from '../../utils/time';
import StatusPill from '../ui/StatusPill';

/**
 * TaskCard — Figma "Task Card / Kanban" (reusable-components).
 * Screen 12 geometry: card 251×138 `rx9.5`, priority/tag pills `h23 rx8`,
 * a hairline divider and a footer of quiet metrics + a 24px assignee avatar.
 */
const PRIORITY_VARIANT = {
    highest: 'danger',
    high: 'danger',
    medium: 'warning',
    low: 'info',
    lowest: 'info',
};

function formatDue(date) {
    if (!date) return null;
    const d = new Date(`${date}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const diff = (d - today) / 86400000;
    if (d < today) return { label: `Overdue ${date}`, className: 'text-[var(--status-danger)]' };
    if (diff === 0) return { label: 'Today', className: 'text-[var(--status-danger)]' };
    if (diff === 1) return { label: 'Tomorrow', className: 'text-[var(--status-warning)]' };
    return { label: date, className: '' };
}

function Tag({ children, color }) {
    if (color) {
        return (
            <span
                className="inline-flex h-6 items-center rounded-[8px] px-2.5 text-[11px] font-medium"
                style={{ backgroundColor: `${color}1f`, color }}
            >
                {children}
            </span>
        );
    }
    return (
        <span className="inline-flex h-6 items-center rounded-[8px] bg-[var(--field-bg)] px-2.5 text-[11px] font-medium text-muted">
            {children}
        </span>
    );
}

export default function TaskCard({ task, onClick, disabled }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id: task.id,
        disabled: Boolean(disabled),
    });

    const due = formatDue(task.due_date);
    const hasPills = task.priority || task.version || task.components?.length > 0 || task.labels?.length > 0;

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
            className="group cursor-pointer rounded-[9.5px] border border-[var(--border-hairline)] bg-[var(--card-bg)] p-[10px] shadow-[var(--shadow-card)] transition hover:border-[var(--accent)]/40 hover:shadow-[var(--shadow-popover)]"
        >
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-1.5">
                    {task.issue_type && (
                        <span
                            className="inline-flex shrink-0 items-center rounded-[6px] px-1.5 py-0.5 text-[10px] font-semibold"
                            style={{
                                backgroundColor: `${task.issue_type.color || '#4b5ef5'}1f`,
                                color: task.issue_type.color || '#4b5ef5',
                            }}
                            title={task.issue_type.name}
                        >
                            {task.issue_type.name}
                        </span>
                    )}
                    <span className="truncate font-mono text-[10px] font-semibold uppercase tracking-wide text-faint">
                        {task.key}
                    </span>
                </div>
                {task.story_points != null && (
                    <span
                        className="shrink-0 rounded-[6px] bg-[var(--sidebar-bg)] px-1.5 py-0.5 text-[10px] font-semibold text-white"
                        title={`${task.story_points} story points`}
                    >
                        {task.story_points}
                    </span>
                )}
            </div>

            <p className={`mt-1.5 text-[13px] font-semibold leading-snug text-ink ${task.completed_at ? 'line-through opacity-60' : ''}`}>
                {task.title}
            </p>

            {hasPills && (
                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                    {task.priority && (
                        <StatusPill
                            size="sm"
                            variant={PRIORITY_VARIANT[task.priority.slug] || 'neutral'}
                            label={task.priority.name}
                        />
                    )}
                    {task.version && <Tag color={task.version.color}>{task.version.name}</Tag>}
                    {task.components?.map((c) => (
                        <Tag key={c.id} color={c.color}>{c.name}</Tag>
                    ))}
                    {task.labels?.map((label) => (
                        <Tag key={label.id} color={label.color || '#4b5ef5'}>{label.name}</Tag>
                    ))}
                </div>
            )}

            <div className="mt-2.5 flex items-center justify-between border-t border-[var(--border-hairline)] pt-2">
                <div className="flex min-w-0 items-center gap-2 text-[11px] font-medium text-muted">
                    {task.checklist_total > 0 && (
                        <span className="flex items-center gap-0.5" title="Checklist">
                            {task.checklist_done_count}/{task.checklist_total}
                        </span>
                    )}
                    {task.comments_count > 0 && <span title="Comments">{task.comments_count} comments</span>}
                    {task.estimate_minutes != null && task.estimate_minutes > 0 && (
                        <span title="Estimate">est {formatMinutes(task.estimate_minutes)}</span>
                    )}
                    {due && <span className={due.className}>{due.label}</span>}
                </div>
                {task.assignee ? (
                    <span
                        className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
                        style={{ backgroundColor: 'var(--accent)' }}
                        title={task.assignee.name}
                    >
                        {task.assignee.name.charAt(0).toUpperCase()}
                    </span>
                ) : (
                    <span className="h-6 w-6 shrink-0 rounded-full border border-dashed border-[var(--border-hairline)]" />
                )}
            </div>
        </div>
    );
}
