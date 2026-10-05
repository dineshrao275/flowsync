import { useState } from 'react';

/**
 * One goal with its progress bar, its evidence disclosure, and its linked
 * tasks.
 *
 * The bar shows a percentage; the disclosure shows the counts behind it
 * ("12 of 15 tasks completed · 34h logged · 2 overdue"). Linked tasks are
 * the human-curated evidence the counts cannot see — a link is a pointer
 * the goal reads, and unlinking removes the pointer, never the task.
 * This component must never compute or display a composite score — no
 * weighting math, no rollups, no "overall" anything. Percentages are
 * per-goal photographs from the backend; anything that combines goals into
 * a person-score is a different feature the plan forbids, not a prop this
 * card is missing.
 */
export default function GoalCard({ goal, onRefresh, refreshing = false, actions = null, onLink = null, onUnlink = null }) {
    const evidence = goal.progress_evidence ?? null;
    const percent = Number(goal.progress_percent ?? 0);
    const [key, setKey] = useState('');
    const [linking, setLinking] = useState(false);

    function disclosure() {
        if (!evidence) return 'No evidence photographed yet.';

        if (evidence.minutes !== undefined) {
            return `${evidence.hours ?? 0}h logged across ${evidence.days_logged ?? 0} day${evidence.days_logged === 1 ? '' : 's'}`;
        }

        const parts = [];
        if (evidence.completed !== undefined) parts.push(`${evidence.completed} completed`);
        if (evidence.created !== undefined) parts.push(`${evidence.created} created`);
        if (evidence.overdue !== undefined) parts.push(`${evidence.overdue} overdue`);

        return parts.length > 0 ? parts.join(' · ') : 'No evidence photographed yet.';
    }

    return (
        <div className="rounded-lg border border-gray-200 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium text-gray-900">{goal.title}</p>
                    <p className="mt-0.5 text-xs text-gray-400">
                        {goal.employee?.name ?? ''} · weight {goal.weight} · {goal.status}
                        {goal.metric_type !== 'manual' && goal.metric_type !== 'none' ? ` · ${goal.metric_type}` : ''}
                    </p>
                </div>
                <div className="flex shrink-0 gap-2">
                    {onRefresh && (
                        <button
                            type="button"
                            onClick={() => onRefresh(goal)}
                            disabled={refreshing}
                            className="text-xs font-medium text-indigo-600 hover:underline disabled:opacity-50"
                        >
                            Refresh evidence
                        </button>
                    )}
                    {actions}
                </div>
            </div>

            <div className="mt-3 h-2 overflow-hidden rounded-full bg-gray-100">
                <div
                    className="h-full rounded-full bg-indigo-500 transition-all"
                    style={{ width: `${Math.min(100, Math.max(0, percent))}%` }}
                />
            </div>
            <div className="mt-1.5 flex items-center justify-between text-xs">
                <span className="text-gray-500">{disclosure()}</span>
                <span className="font-medium text-gray-700">{percent}%</span>
            </div>
            {goal.progress_source === 'manual' && (
                <p className="mt-1 text-xs text-gray-400">Set by hand — no automatic evidence for this goal.</p>
            )}

            {(goal.tasks?.length > 0 || onLink) && (
                <div className="mt-3 border-t border-gray-100 pt-2">
                    <p className="text-xs font-medium text-gray-500">Linked tasks</p>
                    {goal.tasks?.length > 0 ? (
                        <ul className="mt-1 space-y-1 text-xs">
                            {goal.tasks.map((task) => (
                                <li key={task.id} className="flex items-center justify-between gap-2">
                                    <span className="text-gray-600">
                                        {task.key} · {task.title}
                                        {task.completed_at ? ' · done' : ''}
                                    </span>
                                    {onUnlink && (
                                        <button
                                            type="button"
                                            onClick={() => onUnlink(goal, task)}
                                            className="shrink-0 font-medium text-red-600 hover:underline"
                                        >
                                            Unlink
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mt-1 text-xs text-gray-400">No linked tasks yet.</p>
                    )}
                    {onLink && (
                        <form
                            className="mt-2 flex gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (!key.trim()) return;
                                setLinking(true);
                                Promise.resolve(onLink(goal, key.trim())).finally(() => {
                                    setLinking(false);
                                    setKey('');
                                });
                            }}
                        >
                            <input
                                value={key}
                                onChange={(e) => setKey(e.target.value)}
                                placeholder="Task key (PRJ-123)"
                                aria-label="Task key to link"
                                className="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-xs"
                            />
                            <button
                                type="submit"
                                disabled={linking || !key.trim()}
                                className="shrink-0 text-xs font-medium text-indigo-600 hover:underline disabled:opacity-50"
                            >
                                Link
                            </button>
                        </form>
                    )}
                </div>
            )}
        </div>
    );
}
