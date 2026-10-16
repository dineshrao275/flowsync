import { describe, expect, it } from 'vitest';
import { findCard, moveCardInBoard } from './board';

const card = (id, extra = {}) => ({ id, status_id: 1, completed_at: null, parent_id: null, ...extra });
const flat = () => ({
    statuses: [
        { id: 1, tasks: [card(10), card(11)], tasks_count: 2, open_count: 2 },
        { id: 2, tasks: [card(20, { status_id: 2 })], tasks_count: 1, open_count: 1 },
    ],
});

describe('moveCardInBoard', () => {
    it('moves a card across columns at an index and adjusts counts', () => {
        const next = moveCardInBoard(flat(), 10, 2, 0);

        expect(next.statuses[0].tasks.map((t) => t.id)).toEqual([11]);
        expect(next.statuses[1].tasks.map((t) => t.id)).toEqual([10, 20]);
        expect(next.statuses[1].tasks[0].status_id).toBe(2);
        expect([next.statuses[0].tasks_count, next.statuses[1].tasks_count]).toEqual([1, 2]);
        expect([next.statuses[0].open_count, next.statuses[1].open_count]).toEqual([1, 2]);
    });

    it('reorders inside a column without touching counts', () => {
        const next = moveCardInBoard(flat(), 10, 1, 1);

        expect(next.statuses[0].tasks.map((t) => t.id)).toEqual([11, 10]);
        expect(next.statuses[0].tasks_count).toBe(2);
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
