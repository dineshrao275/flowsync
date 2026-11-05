import { describe, expect, it } from 'vitest';
import { findCard, moveCardInBoard, resolveCardMove } from './board';

const card = (id, extra = {}) => ({ id, status_id: 1, completed_at: null, parent_id: null, ...extra });
const flat = () => ({
    statuses: [
        { id: 1, tasks: [card(10), card(11), card(12)], tasks_count: 3, open_count: 3 },
        { id: 2, tasks: [card(20, { status_id: 2 })], tasks_count: 1, open_count: 1 },
    ],
});

describe('moveCardInBoard', () => {
    it('moves a card across columns at an index and adjusts counts', () => {
        const next = moveCardInBoard(flat(), 10, 2, 0);

        expect(next.statuses[0].tasks.map((t) => t.id)).toEqual([11, 12]);
        expect(next.statuses[1].tasks.map((t) => t.id)).toEqual([10, 20]);
        expect(next.statuses[1].tasks[0].status_id).toBe(2);
        expect([next.statuses[0].tasks_count, next.statuses[1].tasks_count]).toEqual([2, 2]);
        expect([next.statuses[0].open_count, next.statuses[1].open_count]).toEqual([2, 2]);
    });

    it('reorders inside a column without touching counts', () => {
        const next = moveCardInBoard(flat(), 10, 1, 1);

        expect(next.statuses[0].tasks.map((t) => t.id)).toEqual([11, 10, 12]);
        expect(next.statuses[0].tasks_count).toBe(3);
    });

    it('does not mutate the input and returns it unchanged for unknown ids', () => {
        const board = flat();
        const snapshot = JSON.stringify(board);

        moveCardInBoard(board, 10, 2, 0);
        expect(JSON.stringify(board)).toBe(snapshot);
        expect(moveCardInBoard(board, 999, 2, 0)).toBe(board);
        expect(moveCardInBoard(board, 10, 999, 0)).toBe(board);
    });

    it('keeps a swimlane card inside its own lane', () => {
        const board = {
            swimlanes: [
                { statuses: [{ id: 1, tasks: [card(10)], tasks_count: 1, open_count: 1 }, { id: 2, tasks: [], tasks_count: 0, open_count: 0 }] },
                { statuses: [{ id: 1, tasks: [card(30)], tasks_count: 1, open_count: 1 }, { id: 2, tasks: [], tasks_count: 0, open_count: 0 }] },
            ],
        };
        const next = moveCardInBoard(board, 30, 2, 0);

        expect(findCard(next, 30)).toEqual({ group: 1, columnIndex: 1, index: 0 });
        expect(next.swimlanes[0]).toBe(board.swimlanes[0]);
    });

    it('does not count completed cards as open', () => {
        const board = { statuses: [{ id: 1, tasks: [card(10, { completed_at: 'x' })], tasks_count: 1, open_count: 0 }, { id: 2, tasks: [], tasks_count: 0, open_count: 0 }] };
        const next = moveCardInBoard(board, 10, 2, 0);

        expect(next.statuses[1].open_count).toBe(0);
        expect(next.statuses[1].tasks_count).toBe(1);
    });
});

describe('resolveCardMove', () => {
    it('returns shouldMove: false when dropping outside a valid column', () => {
        const board = flat();
        expect(resolveCardMove(board, { id: 10 }, null)).toEqual({ shouldMove: false });
        expect(resolveCardMove(board, { id: 10 }, { id: 'unknown-target' })).toEqual({ shouldMove: false });
    });

    it('returns shouldMove: false when dropping onto itself', () => {
        const board = flat();
        expect(resolveCardMove(board, { id: 10 }, { id: 10 })).toEqual({ shouldMove: false });
    });

    it('returns shouldMove: false when dropping the last card onto its own column container', () => {
        const board = flat();
        // card 12 is at index 2 (the last card in column 1)
        expect(resolveCardMove(board, { id: 12 }, { id: 'col-1' })).toEqual({ shouldMove: false });
    });

    it('returns shouldMove: false when dropping a single card in a column onto its own column container', () => {
        const board = flat();
        // card 20 is the only card in column 2 (index 0)
        expect(resolveCardMove(board, { id: 20 }, { id: 'col-2' })).toEqual({ shouldMove: false });
    });

    it('returns shouldMove: true when reordering within the same column by dropping on column container', () => {
        const board = flat();
        // card 10 is at index 0 in column 1 with 3 cards. Dropping on col-1 targets the last index (2).
        const res = resolveCardMove(board, { id: 10 }, { id: 'col-1' });
        expect(res.shouldMove).toBe(true);
        expect(res.task.id).toBe(10);
        expect(res.statusId).toBe(1);
        expect(res.index).toBe(2);
    });

    it('returns shouldMove: true when reordering within the same column by dropping onto another card', () => {
        const board = flat();
        // card 10 is at index 0. Dropping over card 11 (index 1).
        const res = resolveCardMove(board, { id: 10 }, { id: 11 });
        expect(res.shouldMove).toBe(true);
        expect(res.task.id).toBe(10);
        expect(res.statusId).toBe(1);
        expect(res.index).toBe(1);
    });

    it('returns shouldMove: true when moving across columns to another column container', () => {
        const board = flat();
        // card 10 (status 1) moved to col-2
        const res = resolveCardMove(board, { id: 10 }, { id: 'col-2' });
        expect(res.shouldMove).toBe(true);
        expect(res.task.id).toBe(10);
        expect(res.statusId).toBe(2);
        // Column 2 had 1 task, so it appends at index 1
        expect(res.index).toBe(1);
    });

    it('returns shouldMove: true when moving across columns onto a task card', () => {
        const board = flat();
        // card 10 (status 1) dropped onto card 20 (status 2, index 0)
        const res = resolveCardMove(board, { id: 10 }, { id: 20 });
        expect(res.shouldMove).toBe(true);
        expect(res.task.id).toBe(10);
        expect(res.statusId).toBe(2);
        expect(res.index).toBe(0);
    });

    it('works correctly with swimlanes', () => {
        const board = {
            swimlanes: [
                { statuses: [{ id: 1, tasks: [card(10)], tasks_count: 1 }, { id: 2, tasks: [], tasks_count: 0 }] },
                { statuses: [{ id: 1, tasks: [card(30)], tasks_count: 1 }, { id: 2, tasks: [], tasks_count: 0 }] },
            ],
        };
        // Card 30 is in lane 1, column 1 (only card in that column). Dropping on col-1 is unchanged.
        expect(resolveCardMove(board, { id: 30 }, { id: 'col-1' })).toEqual({ shouldMove: false });
        // Card 30 moved to col-2 in its own lane
        const res = resolveCardMove(board, { id: 30 }, { id: 'col-2' });
        expect(res.shouldMove).toBe(true);
        expect(res.statusId).toBe(2);
        expect(res.index).toBe(0);
    });
});
