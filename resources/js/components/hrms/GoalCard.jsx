/**
 * One goal with its progress bar and its evidence disclosure.
 *
 * The bar shows a percentage; the disclosure shows the counts behind it
 * ("12 of 15 tasks completed · 34h logged · 2 overdue"). This component
 * must never compute or display a composite score — no weighting math, no
 * rollups, no "overall" anything. Percentages are per-goal photographs
 * from the backend; anything that combines goals into a person-score is a
 * different feature the plan forbids, not a prop this card is missing.
 */
export default function GoalCard({ goal, onRefresh, refreshing = false, actions = null }) {
    const evidence = goal.progress_evidence ?? null;
    const percent = Number(goal.progress_percent ?? 0);

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
        </div>
    );
}
