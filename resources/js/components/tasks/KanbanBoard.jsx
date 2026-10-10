import { DndContext, PointerSensor, closestCorners, useSensor, useSensors, useDroppable } from '@dnd-kit/core';
import { SortableContext } from '@dnd-kit/sortable';
import TaskCard from './TaskCard';

function Column({ status, canMove, canEdit, onOpen }) {
    const { setNodeRef, isOver } = useDroppable({ id: `col-${status.id}` });

    return (
        <div
            ref={setNodeRef}
            className={`flex w-72 shrink-0 flex-col rounded-2xl border border-[var(--border-hairline)] dark:border-[#2F3A4C] bg-[var(--surface-elevated)] dark:bg-[#1E2638] transition ${
                isOver ? 'border-[#C2410C]/60 bg-[#C2410C]/5' : 'dark:border-[#F97316]/60 dark:bg-[#F97316]/10'
            }`}
        >
            <div className="flex items-center justify-between px-2 pb-2 pt-1">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: status.color || '#cbd5e1' }} />
                    <span className="truncate text-xs font-semibold uppercase tracking-wide text-[#1C1917] dark:text-[#F8FAFC]">{status.name}</span>
                </div>
                <span className="ml-2 shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-[#57534E] dark:text-[#94A3B8] shadow-sm">
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
                        <div className="rounded-lg border border-dashed border-[var(--border-hairline)] dark:border-[#2F3A4C] text-[#A8A29E] dark:text-[#64748B] px-3 py-6 text-center text-xs">
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