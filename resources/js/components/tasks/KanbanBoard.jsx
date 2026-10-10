import { DndContext, PointerSensor, closestCorners, useSensor, useSensors, useDroppable } from '@dnd-kit/core';
import { SortableContext } from '@dnd-kit/sortable';
import TaskCard from './TaskCard';

function Column({ status, canMove, canEdit, onOpen }) {
    const { setNodeRef, isOver } = useDroppable({ id: `col-${status.id}` });

    return (
        <div
            ref={setNodeRef}
            className={`flex w-[270px] shrink-0 flex-col rounded-[12px] bg-[var(--column-bg)] p-[10px] transition ${
                isOver ? 'ring-1 ring-[var(--accent)]/50' : ''
            }`}
        >
            <div className="flex items-center justify-between gap-2 px-0.5 pb-3 pt-0.5">
                <span className="truncate text-[13px] font-semibold text-ink">{status.name}</span>
                <span className="ml-2 inline-flex h-[23px] min-w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-[var(--card-bg)] px-2 text-[11px] font-semibold text-muted">
                    {status.tasks_count}
                </span>
            </div>
            <SortableContext items={status.tasks.map((t) => t.id)}>
                <div className="mt-3 flex flex-col gap-[13px]">
                    {status.tasks.map((task) => (
                        <TaskCard
                            key={task.id}
                            task={task}
                            disabled={!canMove}
                            onClick={() => canEdit && onOpen(task)}
                        />
                    ))}
                    {status.tasks.length === 0 && (
                        <div className="rounded-[9.5px] border border-dashed border-[var(--border-hairline)] px-3 py-6 text-center text-[11px] text-faint">
                            Drop tasks here
                        </div>
                    )}
                </div>
            </SortableContext>
        </div>
    );
}

export default function KanbanBoard({ board, canMove, canEdit, onOpen, onDragEnd }) {
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));

    return (
        <DndContext sensors={sensors} collisionDetection={closestCorners} onDragEnd={onDragEnd}>
            <div className="board-scroll -mx-1 overflow-x-auto px-1 pb-3">
                <div className="flex items-start gap-5">
                    {board.statuses.map((status) => (
                        <Column
                            key={status.id}
                            status={status}
                            canMove={canMove}
                            canEdit={canEdit}
                            onOpen={onOpen}
                        />
                    ))}
                </div>
            </div>
        </DndContext>
    );
}