import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { usePaginated, useResource } from './useResource';

describe('useResource', () => {
    it('resolves data and clears loading', async () => {
        const fetcher = vi.fn().mockResolvedValue({ items: [1, 2] });
        const { result } = renderHook(() => useResource(fetcher));

        expect(result.current.loading).toBe(true);
        expect(result.current.data).toBeNull();

        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(result.current.data).toEqual({ items: [1, 2] });
        expect(result.current.error).toBeNull();
        expect(fetcher).toHaveBeenCalledTimes(1);
    });

    it('captures a rejection as error', async () => {
        const boom = new Error('nope');
        const fetcher = vi.fn().mockRejectedValue(boom);
        const { result } = renderHook(() => useResource(fetcher));

        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(result.current.error).toBe(boom);
        expect(result.current.data).toBeNull();
    });

    it('ignores abort rejections', async () => {
        const aborted = Object.assign(new Error('canceled'), { name: 'CanceledError' });
        const fetcher = vi.fn().mockRejectedValue(aborted);
        const { result } = renderHook(() => useResource(fetcher));

        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(result.current.error).toBeNull();
    });

    it('reload re-runs the fetcher', async () => {
        const fetcher = vi.fn().mockResolvedValueOnce('first').mockResolvedValueOnce('second');
        const { result } = renderHook(() => useResource(fetcher));

        await waitFor(() => expect(result.current.data).toBe('first'));

        act(() => result.current.reload());

        await waitFor(() => expect(result.current.data).toBe('second'));
        expect(fetcher).toHaveBeenCalledTimes(2);
    });
});

describe('usePaginated', () => {
    it('starts on page 1 and refetches on setPage', async () => {
        const fetchPage = vi.fn((page) => Promise.resolve({ page }));
        const { result } = renderHook(() => usePaginated(fetchPage));

        await waitFor(() => expect(result.current.data).toEqual({ page: 1 }));
        expect(result.current.page).toBe(1);

        act(() => result.current.setPage(3));

        await waitFor(() => expect(result.current.data).toEqual({ page: 3 }));
        expect(fetchPage).toHaveBeenLastCalledWith(3, expect.anything());
    });
});
