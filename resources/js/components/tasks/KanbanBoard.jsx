import { useState } from 'react';
import { createPortal } from 'react-dom';
import { DndContext, DragOverlay, KeyboardSensor, PointerSensor, closestCorners, useSensor, useSensors, useDroppable } from '@dnd-kit/core';
import { SortableContext, sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import { useTranslation } from 'react-i18next';
import { DotsThree } from '@phosphor-icons/react';
import Menu from '../ui/Menu';
import Icon from '../ui/Icon';
import TaskCard, { CARD_CLASS, TaskCardBody } from './TaskCard';

const STORAGE_PREFIX = (typeof window !== 'undefined' && window.__FLOWSYNC_CONFIG__?.storagePrefix) || 'flowsync';
const COLLAPSE_KEY = `${STORAGE_PREFIX}.board.collapsed`;

function readCollapsed() {
    try {
        return new Set(JSON.parse(window.localStorage.getItem(COLLAPSE_KEY) ?? '[]'));
    } catch {
        return new Set();
    }
}

function WipBadge({ status }) {
    const { t } = useTranslation();
    if (status.wip_limit == null) {
        return (
            <span className="ml-2 shrink-0 rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-gray-600 shadow-sm">
                {status.tasks_count}
            </span>
        );
    }
    const over = status.open_count >= status.wip_limit;
    return (
        <span
            className={`ml-2 shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold shadow-sm ${
                over ? 'bg-red-100 text-red-700' : 'bg-white text-gray-600'
            }`}
            title={t('tms.board.wipTitle', { open: status.open_count, limit: status.wip_limit })}
        >
            {status.tasks_count}
            <span className={`${over ? 'text-red-400' : 'text-gray-400'}`}> / </span>
            {status.wip_limit}
        </span>
    );
}

/** Inline "add a card to this column" — Enter submits, Escape cancels, stays open for rapid entry. */
function QuickAdd({ status, onQuickAdd }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [busy, setBusy] = useState(false);

    async function submit(event) {
        event.preventDefault();
        const value = title.trim();
        if (!value || busy) return;
        setBusy(true);
        try {
            await onQuickAdd(status.id, value);
            setTitle('');
        } catch {
            // The page toasts the failure; keep the text so nothing typed is lost.
        } finally {
            setBusy(false);
        }
    }

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="mt-2 w-full rounded-lg px-2 py-1.5 text-left text-xs font-medium text-gray-600 transition hover:bg-white hover:text-gray-900"
            >
                + {t('tms.board.quickAdd')}
            </button>
        );
    }

    return (
        <form onSubmit={submit} className="mt-2">
            <input
                autoFocus
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        setOpen(false);
                        setTitle('');
                    }
                }}
                disabled={busy}
                aria-label={t('tms.board.quickAddLabel', { name: status.name })}
                placeholder={t('tms.board.quickAddPlaceholder')}
                className="block w-full rounded-lg border border-line-input bg-white px-2.5 py-2 text-sm text-gray-900 shadow-sm focus:border-[var(--accent)] focus:outline-none disabled:opacity-60"
            />
        </form>
    );
}

const WIDTHS = { compact: 'sm:w-60', default: 'sm:w-72', wide: 'sm:w-96' };

function Column({ status, canMove, canEdit, canCreate, collapsed, onToggle, onOpen, onQuickAdd, width = 'default', compact = false, manage = null }) {
    const { t } = useTranslation();
    const { setNodeRef, isOver } = useDroppable({ id: `col-${status.id}` });

    if (collapsed) {
        return (
            <div
                ref={setNodeRef}
                className={`flex w-12 shrink-0 snap-start flex-col items-center rounded-xl border py-2 transition ${
                    isOver ? 'border-[var(--accent-ring)] bg-[var(--accent-soft)]' : 'border-gray-100 bg-gray-50/80'
                }`}
            >
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded="false"
                    aria-label={t('tms.board.expandColumn', { name: status.name })}
                    className="flex flex-col items-center gap-2 px-2 py-1 text-gray-600 hover:text-gray-900"
                >
                    <span className="rounded-full bg-white px-2 py-0.5 text-xs font-semibold shadow-sm">{status.tasks_count}</span>
                    <span className="text-xs font-semibold [writing-mode:vertical-rl]">{status.name}</span>
                </button>
            </div>
        );
    }

    return (
        <div
            ref={setNodeRef}
            className={`flex w-[85vw] max-w-96 shrink-0 snap-start flex-col rounded-xl border p-2 transition ${WIDTHS[width] ?? WIDTHS.default} ${
                isOver ? 'border-[var(--accent-ring)] bg-[var(--accent-soft)]' : 'border-gray-100 bg-gray-50/80'
            }`}
        >
            <div className="flex items-center justify-between px-2 pb-2 pt-1">
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded="true"
                    aria-label={t('tms.board.collapseColumn', { name: status.name })}
                    className="flex min-w-0 items-center gap-2 rounded text-left"
                >
                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: status.color || '#cbd5e1' }} />
                    <span className="truncate text-xs font-semibold text-gray-700">{status.name}</span>
                </button>
                <div className="flex items-center">
                    <WipBadge status={status} />
                    {manage && (
                        <Menu
                            label={t('tms.columns.menu', { name: status.name })}
                            trigger={(props) => (
                                <button type="button" {...props} aria-label={t('tms.columns.menu', { name: status.name })} className="ml-1 rounded p-1 text-gray-500 hover:bg-gray-200 hover:text-gray-900">
                                    <Icon as={DotsThree} size="sm" weight="bold" />
                                </button>
                            )}
                            items={[
                                { key: 'edit', label: t('tms.columns.editShort'), onSelect: () => manage.onEdit(status) },
                                { key: 'left', label: t('tms.columns.moveLeft'), disabled: manage.index === 0, onSelect: () => manage.onMove(status, manage.index) },
                                { key: 'right', label: t('tms.columns.moveRight'), disabled: manage.index === manage.count - 1, onSelect: () => manage.onMove(status, manage.index + 2) },
                                { separator: true },
                                { key: 'delete', label: t('tms.columns.deleteShort'), danger: true, disabled: status.tasks_count > 0, onSelect: () => manage.onDelete(status) },
                            ]}
                        />
                    )}
                </div>
            </div>
            <SortableContext items={status.tasks.map((task) => task.id)}>
                <div className="flex flex-col gap-2">
                    {status.tasks.map((task) => (
                        <TaskCard
                            key={task.id}
                            task={task}
                            disabled={!canMove}
                            compact={compact}
                            onClick={() => canEdit && onOpen(task)}
                        />
                    ))}
                    {status.tasks.length === 0 && (
                        <div className="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-500">
                            {t('tms.board.dropHere')}
                        </div>
                    )}
                </div>
            </SortableContext>
            {canCreate && onQuickAdd && <QuickAdd status={status} onQuickAdd={onQuickAdd} />}
        </div>
    );
}

function BoardRow({ statuses, canMove, canEdit, canCreate, collapsed, onToggle, onOpen, onDragEnd, onQuickAdd, width, compact, manage }) {
    const [activeId, setActiveId] = useState(null);
    // Pointer drags for the mouse; the keyboard sensor (Space lifts, arrows
    // move, Enter drops, announced by dnd-kit's live region) is the drag
    // alternative for keyboard users — no separate move dialog to maintain.
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const activeTask = activeId == null ? null : statuses.flatMap((status) => status.tasks).find((task) => task.id === activeId);

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCorners}
            onDragStart={({ active }) => setActiveId(active.id)}
            onDragCancel={() => setActiveId(null)}
            onDragEnd={(event) => {
                setActiveId(null);
                onDragEnd(event);
            }}
        >
            <div className="flex items-start gap-3">
                {statuses.map((status) => (
                    <Column
                        key={status.id}
                        status={status}
                        canMove={canMove}
                        canEdit={canEdit}
                        canCreate={canCreate}
                        collapsed={collapsed.has(status.id)}
                        onToggle={() => onToggle(status.id)}
                        onOpen={onOpen}
                        onQuickAdd={onQuickAdd}
                        width={width}
                        compact={compact}
                        manage={manage ? { ...manage, index: manage.order.indexOf(status.id), count: manage.order.length } : null}
                    />
                ))}
            </div>
            {/* Portalled: the page wrapper is a transformed ancestor, which would make the
                overlay's fixed positioning relative to it and offset the card from the cursor. */}
            {createPortal(
                <DragOverlay>
                    {activeTask ? (
                        <div className={`${CARD_CLASS} rotate-2 cursor-grabbing shadow-xl`}>
                            <TaskCardBody task={activeTask} />
                        </div>
                    ) : null}
                </DragOverlay>,
                document.body,
            )}
        </DndContext>
    );
}

export default function KanbanBoard({ board, canMove, canEdit, canCreate = false, onOpen, onDragEnd, onQuickAdd, prefs = null, manage = null }) {
    const { t } = useTranslation();
    const swimmers = board.swimlanes;
    const [localCollapsed, setLocalCollapsed] = useState(readCollapsed);
    // Server-side preferences win when provided; the localStorage set is the fallback.
    const collapsed = prefs ? new Set(prefs.collapsed) : localCollapsed;
    const hidden = new Set(prefs?.hidden ?? []);
    const visible = (statuses) => statuses.filter((s) => !hidden.has(s.id) && !(prefs?.hide_empty && s.tasks_count === 0));
    const shown = {
        width: prefs?.width ?? 'default',
        compact: prefs?.density === 'compact',
    };

    function toggle(statusId) {
        if (prefs) {
            const next = new Set(prefs.collapsed);
            if (next.has(statusId)) next.delete(statusId);
            else next.add(statusId);
            prefs.save({ collapsed: [...next] });
            return;
        }
        setLocalCollapsed((current) => {
            const next = new Set(current);
            if (next.has(statusId)) next.delete(statusId);
            else next.add(statusId);
            try {
                window.localStorage.setItem(COLLAPSE_KEY, JSON.stringify([...next]));
            } catch {
                // Private mode: collapse still works for this visit.
            }

            return next;
        });
    }

    const rowProps = { canMove, canEdit, canCreate, collapsed, onToggle: toggle, onOpen, onDragEnd, onQuickAdd, ...shown, manage };

    return (
        <div data-density={prefs?.density ?? 'comfortable'} className="board-scroll -mx-1 snap-x snap-mandatory overflow-x-auto px-1 pb-3 sm:snap-none">
            {swimmers ? (
                <div className="flex flex-col gap-4">
                    {swimmers.map((lane) => (
                        <div key={lane.assignee?.id ?? 'unassigned'} className="space-y-2">
                            <div className="flex items-center justify-between rounded-lg border border-gray-100 bg-white px-3 py-2">
                                <div className="flex items-center gap-2 text-sm font-medium text-gray-700">
                                    <span
                                        className="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold text-white"
                                        style={{ backgroundColor: 'var(--accent)' }}
                                    >
                                        {(lane.assignee?.name ?? 'U').charAt(0).toUpperCase()}
                                    </span>
                                    {lane.assignee ? lane.assignee.name : t('tms.board.unassigned')}
                                </div>
                                <span className="text-xs text-gray-500">
                                    {t('tms.board.laneTotals', { open: lane.totals.open, done: lane.totals.done })}
                                </span>
                            </div>
                            <BoardRow statuses={visible(lane.statuses)} {...rowProps} />
                        </div>
                    ))}
                </div>
            ) : (
                <BoardRow statuses={visible(board.statuses)} {...rowProps} />
            )}
        </div>
    );
}
