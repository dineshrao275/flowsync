import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import useDebouncedValue from './useDebouncedValue';

describe('useDebouncedValue', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('publishes only the last value after the pause', () => {
        const { result, rerender } = renderHook(({ v }) => useDebouncedValue(v, 300), { initialProps: { v: 'a' } });

        rerender({ v: 'ab' });
        rerender({ v: 'abc' });
        expect(result.current).toBe('a');

        act(() => vi.advanceTimersByTime(299));
        expect(result.current).toBe('a');

        act(() => vi.advanceTimersByTime(1));
        expect(result.current).toBe('abc');
    });
});
