import { describe, expect, it } from 'vitest';
import { monthGrid } from './CalendarView';

describe('monthGrid', () => {
    it('is six Monday-first weeks that cover the whole month', () => {
        const days = monthGrid(new Date(2026, 9, 15)); // October 2026 (the 1st is a Thursday)

        expect(days).toHaveLength(42);
        expect(days[0].getDay()).toBe(1); // Monday
        expect(days[0].getDate()).toBe(28); // Sep 28
        expect(days.some((d) => d.getMonth() === 9 && d.getDate() === 31)).toBe(true);
    });

    it('starts the grid on the 1st when the month begins on a Monday', () => {
        const days = monthGrid(new Date(2026, 5, 10)); // June 2026 starts on a Monday

        expect(days[0].getDate()).toBe(1);
        expect(days[0].getMonth()).toBe(5);
    });
});
