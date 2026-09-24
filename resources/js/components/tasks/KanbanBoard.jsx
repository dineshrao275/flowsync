import { DndContext, PointerSensor, closestCorners, useSensor, useSensors, useDroppable } from '@dnd-kit/core';
import { SortableContext } from '@dnd-kit/sortable';
import TaskCard from './TaskCard';

function Column({ status, canMove, canEdit, onOpen }) {
    const { setNodeRef, isOver } = useDroppable({ id: `col-${status.id}` });

    return (
        <div
            ref={setNodeRef}
            className={`flex w-72 shrink-0 flex-col rounded-xl border p-2 transition ${
                isOver ? 'border-indigo-300 bg-indigo-50/60' : 'border-gray-100 bg-gray-50/80'
            }`}
        >
            <div className="flex items-center justify-between px-2 pb-2 pt-1">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: status.color || '#cbd5e1' }} />
                    <span className="truncate text-xs font-semibold uppercase tracking-wide text-gray-600">{status.name}</span>
                </div>
                <span className="ml-2 shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-gray-500 shadow-sm">
                    {status.tasks_count}
                </span>
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
                <div className="flex items-start gap-3">
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