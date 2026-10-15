import { DndContext, KeyboardSensor, PointerSensor, closestCorners, useSensor, useSensors, useDroppable } from '@dnd-kit/core';
import { SortableContext, sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import { useTranslation } from 'react-i18next';
import TaskCard from './TaskCard';

function WipBadge({ status }) {
    const { t } = useTranslation();
    if (status.wip_limit == null) {
        return (
            <span className="ml-2 shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-gray-500 shadow-sm">
                {status.tasks_count}
            </span>
        );
    }
    const over = status.open_count >= status.wip_limit;
    return (
        <span
            className={`ml-2 shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold shadow-sm ${
                over ? 'bg-red-100 text-red-600' : 'bg-white text-gray-500'
            }`}
            title={t('tms.board.wipTitle', { open: status.open_count, limit: status.wip_limit })}
        >
            {status.tasks_count}
            <span className={`${over ? 'text-red-400' : 'text-gray-300'}`}> / </span>
            {status.wip_limit}
        </span>
    );
}

function Column({ status, canMove, canEdit, onOpen }) {
    const { t } = useTranslation();
    const { setNodeRef, isOver } = useDroppable({ id: `col-${status.id}` });

    return (
        <div
            ref={setNodeRef}
            className={`flex w-72 shrink-0 flex-col rounded-xl border p-2 transition ${
                isOver ? 'border-[var(--accent-ring)] bg-[var(--accent-soft)]' : 'border-gray-100 bg-gray-50/80'
            }`}
        >
            <div className="flex items-center justify-between px-2 pb-2 pt-1">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: status.color || '#cbd5e1' }} />
                    <span className="truncate text-xs font-semibold text-gray-600">{status.name}</span>
                </div>
                <WipBadge status={status} />
            </div>
            <SortableContext items={status.tasks.map((t) => t.id)}>
                <div className="flex flex-col gap-2">
                    {status.tasks.map((task) => (
                        <TaskCard
                            key={task.id}
                            task={task}
                            disabled={!canMove}
                            onClick={() => canEdit && onOpen(task)}
                        />
                    ))}
                    {status.tasks.length === 0 && (
                        <div className="rounded-lg border border-dashed border-gray-200 px-3 py-6 text-center text-xs text-gray-400">
                            {t('tms.board.dropHere')}
                        </div>
                    )}
                </div>
            </SortableContext>
        </div>
    );
}

function BoardRow({ statuses, canMove, canEdit, onOpen, onDragEnd }) {
    // Pointer drags for the mouse; the keyboard sensor (Space lifts, arrows
    // move, Enter drops, announced by dnd-kit's live region) is the drag
    // alternative for keyboard users — no separate move dialog to maintain.
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    return (
        <DndContext sensors={sensors} collisionDetection={closestCorners} onDragEnd={onDragEnd}>
            <div className="flex items-start gap-3">
                {statuses.map((status) => (
                    <Column
                        key={status.id}
                        status={status}
                        canMove={canMove}
                        canEdit={canEdit}
                        onOpen={onOpen}
                    />
                ))}
            </div>
        </DndContext>
    );
}

export default function KanbanBoard({ board, canMove, canEdit, onOpen, onDragEnd }) {
    const { t } = useTranslation();
    const swimmers = board.swimlanes;

    return (
        <div className="board-scroll -mx-1 overflow-x-auto px-1 pb-3">
            {swimmers ? (
                <div className="flex flex-col gap-4">
                    {swimmers.map((lane) => (
                        <div key={lane.assignee?.id ?? 'unassigned'} className="space-y-2">
                            <div className="flex items-center justify-between rounded-lg border border-gray-100 bg-white px-3 py-2">
                                <div className="flex items-center gap-2 text-sm font-medium text-gray-700">
                                    <span
                                        className="flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold text-white"
                                        style={{ backgroundColor: 'var(--accent)' }}
                                    >
                                        {(lane.assignee?.name ?? 'U').charAt(0).toUpperCase()}
                                    </span>
                                    {lane.assignee ? lane.assignee.name : t('tms.board.unassigned')}
                                </div>
                                <span className="text-xs text-gray-400">
                                    {t('tms.board.laneTotals', { open: lane.totals.open, done: lane.totals.done })}
                                </span>
                            </div>
                            <BoardRow
                                statuses={lane.statuses}
                                canMove={canMove}
                                canEdit={canEdit}
                                onOpen={onOpen}
                                onDragEnd={onDragEnd}
                            />
                        </div>
                    ))}
                </div>
            ) : (
                <BoardRow
                    statuses={board.statuses}
                    canMove={canMove}
                    canEdit={canEdit}
                    onOpen={onOpen}
                    onDragEnd={onDragEnd}
                />
            )}
        </div>
    );
}
