import { describe, expect, it } from 'vitest';
import { barFor } from './TimelineView';

const from = new Date(2026, 9, 5); // Monday 5 Oct 2026

describe('barFor', () => {
    it('places a task by its start and due dates as percentages of the window', () => {
        const bar = barFor({ start_date: '2026-10-07', due_date: '2026-10-09' }, from, 70);

        expect(bar.left).toBeCloseTo((2 / 70) * 100);
        expect(bar.width).toBeCloseTo((3 / 70) * 100);
        expect(bar.clippedLeft).toBe(false);
    });

    it('draws a one-date task as a one-day marker', () => {
        const bar = barFor({ due_date: '2026-10-05' }, from, 70);

        expect(bar.left).toBe(0);
        expect(bar.width).toBeCloseTo(100 / 70);
    });

    it('clips bars that run past the window and drops ones fully outside', () => {
        const clipped = barFor({ start_date: '2026-09-28', due_date: '2026-10-08' }, from, 70);
        expect(clipped.clippedLeft).toBe(true);
        expect(clipped.left).toBe(0);

        expect(barFor({ start_date: '2026-08-01', due_date: '2026-08-10' }, from, 70)).toBeNull();
        expect(barFor({ start_date: '2027-01-01', due_date: '2027-01-05' }, from, 70)).toBeNull();
        expect(barFor({}, from, 70)).toBeNull();
    });
});
