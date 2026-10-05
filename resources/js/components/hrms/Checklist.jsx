import Button from '../ui/Button';
import { Table, Th, Td, TableEmpty } from '../ui/Table';

const STATUS_COLORS = {
    pending: '#f59e0b',
    in_progress: '#0ea5e9',
    done: '#10b981',
    skipped: '#6b7280',
    waived: '#8b5cf6',
};

const SCOPE_LABELS = {
    hr: 'HR',
    manager: 'Manager',
    employee: 'Employee',
    it: 'IT',
};

function StatusPill({ status, label }) {
    const color = STATUS_COLORS[status] ?? '#6b7280';

    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
            style={{ backgroundColor: `${color}22`, color }}
        >
            <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: color }} />
            {label ?? status}
        </span>
    );
}

function isOverdue(task) {
    if (!task.due_date || task.status === 'done' || task.status === 'waived' || task.status === 'skipped') return false;
    return task.due_date < new Date().toISOString().slice(0, 10);
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(`${value}T00:00:00`).toLocaleDateString();
}

/**
 * One checklist for both lifecycle phases: onboarding tasks and offboarding
 * tasks share the same shape, so the grouping, the overdue highlighting and
 * the waive-with-reason flow live here once.
 *
 * Items group by owner scope — each party sees their own pile first — and
 * the waive button prompts for the reason the backend requires, because a
 * waive without one is a 422 and a modal for a single text field would be
 * ceremony for ceremony’s sake.
 */
export default function Checklist({ tasks, canAct, canWaive, onComplete, onWaive, canConvert, onConvert, onSync }) {
    if (!tasks || tasks.length === 0) {
        return <TableEmpty>No checklist items.</TableEmpty>;
    }

    const groups = [];
    const seen = new Map();

    tasks.forEach((task) => {
        if (!seen.has(task.owner_scope)) {
            seen.set(task.owner_scope, []);
            groups.push(task.owner_scope);
        }

        seen.get(task.owner_scope).push(task);
    });

    return (
        <div className="space-y-4">
            {groups.map((scope) => (
                <div key={scope}>
                    <h4 className="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">
                        {SCOPE_LABELS[scope] ?? scope}
                    </h4>
                    <Table>
                        <thead>
                            <tr>
                                <Th>Item</Th>
                                <Th>Due</Th>
                                <Th>Status</Th>
                                {canAct && (
                                    <Th>
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {seen.get(scope).map((task) => {
                                const overdue = isOverdue(task);
                                const open = task.status === 'pending' || task.status === 'in_progress';

                                return (
                                    <tr key={task.id}>
                                        <Td>
                                            <span className="block font-medium text-gray-900">{task.title}</span>
                                            {task.description && (
                                                <span className="block text-xs text-gray-400">{task.description}</span>
                                            )}
                                            {task.note && (
                                                <span className="block text-xs italic text-gray-500">“{task.note}”</span>
                                            )}
                                        </Td>
                                        <Td>
                                            <span className={overdue ? 'font-semibold text-red-600' : ''}>
                                                {formatDate(task.due_date)}
                                                {overdue && ' · overdue'}
                                            </span>
                                        </Td>
                                        <Td>
                                            <StatusPill status={task.status} />
                                        </Td>
                                        {canAct && (
                                            <Td>
                                                {open && (
                                                    <div className="flex justify-end gap-2">
                                                        <Button size="sm" variant="secondary" onClick={() => onComplete?.(task)}>
                                                            Done
                                                        </Button>
                                                        {canConvert && task.category === 'task' && (
                                                            <>
                                                                <Button size="sm" variant="secondary" onClick={() => onConvert?.(task)}>
                                                                    Convert
                                                                </Button>
                                                                <Button size="sm" variant="ghost" onClick={() => onSync?.(task)}>
                                                                    Sync
                                                                </Button>
                                                            </>
                                                        )}
                                                        {canWaive && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => {
                                                                const reason = window.prompt(
                                                                    `Why is “${task.title}” being waived?`,
                                                                    '',
                                                                );

                                                                if (reason !== null) onWaive?.(task, reason);
                                                            }}
                                                        >
                                                            Waive
                                                        </Button>
                                                        )}
                                                    </div>
                                                )}
                                            </Td>
                                        )}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </Table>
                </div>
            ))}
        </div>
    );
}
