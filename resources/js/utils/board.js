/**
 * Pure board transforms for optimistic UI. A board is either flat
 * (`{ statuses: [...] }`) or grouped into swimlanes
 * (`{ swimlanes: [{ statuses: [...] }] }`); a card only ever moves between the
 * columns of the group it already sits in (a lane is its assignee's row).
 */
function groups(board) {
    return board.swimlanes ? board.swimlanes.map((lane) => lane.statuses) : [board.statuses];
}

/** Locate a task: `{ group, columnIndex, index }` or null. */
export function findCard(board, taskId) {
    const all = groups(board);

    for (let g = 0; g < all.length; g += 1) {
        for (let c = 0; c < all[g].length; c += 1) {
            const index = all[g][c].tasks.findIndex((task) => task.id === taskId);
            if (index >= 0) return { group: g, columnIndex: c, index };
        }
    }

    return null;
}

const isOpen = (task) => !task.completed_at && !task.parent_id;

/**
 * Return a new board with `taskId` moved to column `statusId` at `index`.
 * Counts are adjusted locally; the server refetch that follows a successful
 * move is the source of truth. Unknown task/column, or a destination outside
 * the card's group, returns the board unchanged (same reference).
 */
export function moveCardInBoard(board, taskId, statusId, index) {
    const origin = findCard(board, taskId);
    if (!board || !origin) return board;

    const source = groups(board)[origin.group];
    const destColumn = source.find((status) => status.id === statusId);
    if (!destColumn) return board;

    const task = source[origin.columnIndex].tasks[origin.index];
    const moved = { ...task, status_id: statusId };

    const nextStatuses = source.map((status) => {
        const from = status.id === source[origin.columnIndex].id;
        const to = status.id === statusId;
        if (!from && !to) return status;

        let tasks = from ? status.tasks.filter((card) => card.id !== taskId) : status.tasks;
        if (to) {
            const at = Math.max(0, Math.min(index, tasks.length));
            tasks = [...tasks.slice(0, at), moved, ...tasks.slice(at)];
        }

        const crossing = from !== to;
        const delta = crossing ? (to ? 1 : -1) : 0;

        return {
            ...status,
            tasks,
            tasks_count: status.tasks_count != null ? status.tasks_count + delta : status.tasks_count,
            open_count: status.open_count != null && isOpen(task) ? status.open_count + delta : status.open_count,
        };
    });

    return board.swimlanes
        ? { ...board, swimlanes: board.swimlanes.map((lane, g) => (g === origin.group ? { ...lane, statuses: nextStatuses } : lane)) }
        : { ...board, statuses: nextStatuses };
}

/**
 * Determine whether a drag-and-drop release represents a valid move.
 * Returns `{ shouldMove: true, task, statusId, index }` or `{ shouldMove: false }`.
 */
export function resolveCardMove(board, active, over) {
    if (!over || !active || active.id === over.id || !board) {
        return { shouldMove: false };
    }

    const origin = findCard(board, active.id);
    if (!origin) {
        return { shouldMove: false };
    }

    const laneStatuses = board.swimlanes ? board.swimlanes[origin.group]?.statuses : board.statuses;
    if (!laneStatuses || !laneStatuses[origin.columnIndex]) {
        return { shouldMove: false };
    }

    const sourceColumn = laneStatuses[origin.columnIndex];
    const originIndex = origin.index;
    const task = sourceColumn.tasks[originIndex];
    if (!task) {
        return { shouldMove: false };
    }

    let statusId;
    let index;
    const colMatch = String(over.id).match(/^col-(\d+)$/);
    if (colMatch) {
        statusId = Number(colMatch[1]);
        const destColumn = laneStatuses.find((s) => s.id === statusId);
        if (!destColumn) {
            return { shouldMove: false };
        }
        const isSameColumn = sourceColumn.id === statusId;
        index = isSameColumn
            ? Math.max(0, destColumn.tasks.length - 1)
            : destColumn.tasks.length;
    } else {
        let found = null;
        laneStatuses.forEach((s) => {
            const i = s.tasks.findIndex((t) => t.id === over.id);
            if (i >= 0 && !found) found = { status: s, index: i };
        });
        if (!found) {
            return { shouldMove: false };
        }
        statusId = found.status.id;
        index = found.index;
    }

    const isSameColumn = sourceColumn.id === statusId;
    if (isSameColumn && originIndex === index) {
        return { shouldMove: false };
    }

    return {
        shouldMove: true,
        task,
        statusId,
        index,
    };
}
