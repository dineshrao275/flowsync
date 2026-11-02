import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Employees from './Employees';

const get = vi.fn();

vi.mock('../../services/api', () => ({ default: { get: (...a) => get(...a), post: vi.fn() }, fieldErrors: () => ({}) }));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => false }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));
vi.mock('../../components/ui/ListSavedFilters', () => ({ default: () => null }));

const directoryCalls = () => get.mock.calls.filter(([url]) => url === '/hrms/employees');

describe('Employees directory', () => {
    beforeEach(() => {
        get.mockReset();
        // Never answers: the test inspects how the page cancels, not what it renders.
        get.mockImplementation(() => new Promise(() => {}));
    });

    // Without this, a slow unfiltered response could land after a filtered one and the list
    // would no longer match the search box (seen under load in the Playwright run).
    it('aborts the previous directory request when the filters change', async () => {
        render(<MemoryRouter><Employees /></MemoryRouter>);
        await waitFor(() => expect(directoryCalls().length).toBeGreaterThan(0));

        fireEvent.change(screen.getByPlaceholderText(/name or code/i), { target: { value: 'Alice' } });
        await waitFor(() => expect(directoryCalls().some(([, c]) => c.params.q === 'Alice')).toBe(true));

        const calls = directoryCalls();
        const latest = calls.at(-1)[1];

        expect(latest.params.q).toBe('Alice');
        expect(latest.signal).toBeInstanceOf(AbortSignal);
        expect(latest.signal.aborted).toBe(false);
        expect(calls.slice(0, -1).every(([, c]) => c.signal.aborted)).toBe(true);
    });
});
