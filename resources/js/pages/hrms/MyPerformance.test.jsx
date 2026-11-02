import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MyPerformance from './MyPerformance';

const get = vi.fn();

vi.mock('../../services/api', () => ({ default: { get: (...a) => get(...a), post: vi.fn() }, fieldErrors: () => ({}) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const forbidden = () => Promise.reject({ response: { status: 403 } });

// An Employee holds only `hrms.performance.view_own`: the directory (`/hrms/employees`) is closed
// to them, so the page must find its own record through `/my/hr`.
describe('MyPerformance as a self-service Employee', () => {
    beforeEach(() => {
        get.mockReset();
        get.mockImplementation((url) => {
            if (url === '/my/hr') return Promise.resolve({ data: { profile: { id: 7 } } });
            if (url === '/hrms/employees') return forbidden();
            if (url === '/hrms/performance/cycles') return Promise.resolve({ data: { cycles: [{ id: 1, name: 'H1' }] } });
            if (url.endsWith('/goals')) return Promise.resolve({ data: { goals: [{ id: 9, employee_id: 7, title: 'Ship the thing', weight: '50', status: 'active', metric_type: 'manual' }] } });
            return Promise.resolve({ data: { check_ins: [], feedback_requests: [], one_on_ones: [] } });
        });
    });

    it('shows the caller\'s goals without ever reading the employee directory', async () => {
        render(<MemoryRouter><MyPerformance /></MemoryRouter>);

        expect(await screen.findByText('Ship the thing')).toBeInTheDocument();
        expect(get.mock.calls.map(([url]) => url)).not.toContain('/hrms/employees');
        expect(get).toHaveBeenCalledWith('/hrms/performance/cycles/1/goals', { params: { employee_id: 7 } });
    });
});
