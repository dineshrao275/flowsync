import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { formatMinutes } from '../../utils/time';

const PRIORITY_DOT = {
    highest: '#ef4444',
    high: '#f97316',
    medium: '#f59e0b',
    low: '#10b981',
    lowest: '#94a3b8',
};

function formatDue(date) {
    if (!date) return null;
    const d = new Date(`${date}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const diff = (d - today) / 86400000;
    if (d < today) return { label: `Overdue ${date}`, className: 'text-red-600' };
    if (diff === 0) return { label: `Today ${date}`, className: 'text-red-600' };
    if (diff === 1) return { label: `Tomorrow ${date}`, className: 'text-amber-600' };
    return { label: date, className: 'text-gray-500' };
}

export default function TaskCard({ task, onClick, disabled }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id: task.id,
        disabled: Boolean(disabled),
    });

    const due = formatDue(task.due_date);

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
            className="group cursor-pointer rounded-lg border border-gray-200 bg-white px-3 py-2.5 shadow-sm transition hover:border-indigo-300 hover:shadow-md active:z-10"
        >
            <div className="flex items-start justify-between gap-2">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{task.key}</span>
                {task.priority && (
                    <span className="flex items-center gap-1 text-[11px] font-medium text-gray-500">
                        <span
                            className="h-2 w-2 rounded-full"
                            style={{ backgroundColor: PRIORITY_DOT[task.priority.slug] || task.priority.color || '#94a3b8' }}
                        />
                        {task.priority.name}
                    </span>
                )}
            </div>
            <p className={`mt-1 text-sm font-medium leading-snug text-gray-900 ${task.completed_at ? 'line-through opacity-60' : ''}`}>
                {task.title}
            </p>
            {task.labels?.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {task.labels.map((label) => (
                        <span
                            key={label.id}
                            className="rounded-full px-2 py-0.5 text-[10px] font-medium"
                            style={{ backgroundColor: `${label.color || '#e2e8f0'}22`, color: label.color || '#475569' }}
                        >
                            {label.name}
                        </span>
                    ))}
                </div>
            )}
            <div className="mt-2 flex items-center justify-between">
                <div className="flex items-center gap-2 text-[11px] text-gray-400">
                    {task.subtasks_count > 0 && (
                        <span className="flex items-center gap-0.5" title="Subtasks">
                            {task.subtasks_count} sub
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
                        className="flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold text-white"
                        style={{ backgroundColor: 'var(--accent)' }}
                        title={task.assignee.name}
                    >
                        {task.assignee.name.charAt(0).toUpperCase()}
                    </span>
                ) : (
                    <span className="h-5 w-5 rounded-full border border-dashed border-gray-300" />
                )}
            </div>
        </div>
    );
}