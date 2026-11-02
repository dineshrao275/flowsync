import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ImprovementPlans from './ImprovementPlans';

const get = vi.fn();

vi.mock('../../services/api', () => ({ default: { get: (...a) => get(...a), post: vi.fn(), put: vi.fn() }, fieldErrors: () => ({}) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const answer = (canOpen) => get.mockImplementation((url) => {
    if (url === '/hrms/improvement-plans') return Promise.resolve({ data: { plans: [], can_open: canOpen } });
    return Promise.resolve({ data: { employees: [{ id: 1, name: 'Ada' }] } });
});

describe('ImprovementPlans open action', () => {
    beforeEach(() => get.mockReset());

    it('offers no dead-end action and asks for no directory when the caller cannot open plans', async () => {
        answer(false);
        render(<ImprovementPlans />);

        await screen.findByText('No improvement plans.');
        expect(screen.queryByRole('button', { name: 'Open a plan' })).toBeNull();
        expect(get.mock.calls.map(([url]) => url)).not.toContain('/hrms/employees');
    });

    it('offers the action and loads the people picker when the caller can open plans', async () => {
        answer(true);
        render(<ImprovementPlans />);

        expect(await screen.findByRole('button', { name: 'Open a plan' })).toBeInTheDocument();
        await waitFor(() => expect(get.mock.calls.map(([url]) => url)).toContain('/hrms/employees'));
    });
});
