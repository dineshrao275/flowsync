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
