import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import EmployeeChanges from './EmployeeChanges';

const get = vi.fn();
let perms = [];

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: (p) => perms.includes(p) }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

describe('EmployeeChanges data loading', () => {
    beforeEach(() => {
        get.mockReset();
        get.mockImplementation((url) => Promise.resolve({ data: url.includes('employee-changes') ? { changes: [], types: ['transfer'] } : { filters: {}, employees: [{ id: 1, name: 'Ann' }] } }));
    });

    it('does not request the employee directory for someone who cannot view employees (it would 403)', async () => {
        perms = [];
        render(<EmployeeChanges />);

        expect(await screen.findByText('No changes yet.')).toBeInTheDocument();
        expect(get.mock.calls.map((c) => c[0])).not.toContain('/hrms/employees');
        expect(screen.queryByRole('button', { name: 'Request a change' })).toBeNull();
    });

    it('loads the directory and offers Request a change to someone who can view employees', async () => {
        perms = ['hrms.employees.view'];
        render(<EmployeeChanges />);

        expect(await screen.findByRole('button', { name: 'Request a change' })).toBeInTheDocument();
        expect(get.mock.calls.map((c) => c[0])).toContain('/hrms/employees');
    });
});
