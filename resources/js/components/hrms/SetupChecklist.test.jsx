import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SetupChecklist from './SetupChecklist';

const get = vi.fn();

vi.mock('../../services/api', () => ({ default: { get: (...args) => get(...args) } }));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ check: () => true }) }));

const respond = (map) => get.mockImplementation((url) => (map[url] ? Promise.resolve({ data: map[url] }) : Promise.reject(new Error('no'))));

describe('SetupChecklist', () => {
    // Braces matter: a returned mock would be run by vitest as a teardown hook.
    beforeEach(() => { get.mockReset(); });

    it('lists open steps with links and a progress bar', async () => {
        respond({
            '/hrms/employees': { pagination: { total: 1 } },
            '/hrms/org': { departments: [{ direct_count: 0 }] },
            '/hrms/holidays/calendars': { calendars: [{ holidays_count: 12 }] },
            '/hrms/leave/policies': { leave_policies: [{ id: 1 }] },
            '/hrms/payroll/structures': { structures: [] },
        });
        render(<MemoryRouter><SetupChecklist /></MemoryRouter>);

        expect(await screen.findByText('Add your people')).toBeInTheDocument();
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '2');
        expect(screen.getAllByRole('link', { name: 'Open' })).toHaveLength(3);
        expect(screen.getByText('Add a holiday calendar')).toHaveClass('line-through');
    });

    it('skips steps whose probe fails and hides when everything is done', async () => {
        respond({
            '/hrms/employees': { pagination: { total: 5 } },
            '/hrms/org': { departments: [{ direct_count: 3 }] },
            '/hrms/holidays/calendars': { calendars: [{ holidays_count: 1 }] },
            '/hrms/leave/policies': { leave_policies: [{ id: 1 }] },
            // payroll probe rejects -> ignored, not shown as a failed step
        });
        const { container } = render(<MemoryRouter><SetupChecklist /></MemoryRouter>);

        await waitFor(() => expect(get).toHaveBeenCalledTimes(5));
        await waitFor(() => expect(container).toBeEmptyDOMElement());
    });
});
