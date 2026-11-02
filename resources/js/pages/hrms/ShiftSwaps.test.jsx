import { render, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShiftSwaps from './ShiftSwaps';

const get = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => false, user: {} }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../components/ui/ConfirmDialog', () => ({ useConfirm: () => vi.fn() }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

describe('ShiftSwaps colleague picker', () => {
    beforeEach(() => {
        get.mockReset();
        get.mockResolvedValue({ data: { swaps: [], colleagues: [] } });
    });

    it('reads the names-only colleague list, never the employee directory an Employee cannot call', async () => {
        render(<MemoryRouter><ShiftSwaps /></MemoryRouter>);

        await waitFor(() => expect(get).toHaveBeenCalledWith('/hrms/shift-swaps/colleagues'));
        expect(get.mock.calls.map(([url]) => url)).not.toContain('/hrms/employees');
    });
});
