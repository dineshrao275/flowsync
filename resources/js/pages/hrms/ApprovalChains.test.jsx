import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ApprovalChains from './ApprovalChains';

const get = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), put: vi.fn(), post: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const payload = {
    chains: [{ domain: 'loans', label: 'Loans', customised: false, steps: [{ type: 'manager' }], fields: ['amount'], sla_hours: null }],
    options: {
        step_types: [{ value: 'manager', label: 'Reporting manager' }, { value: 'role', label: 'Role' }],
        roles: [{ slug: 'admin', name: 'Administrator' }],
        modes: ['sequential', 'parallel_any', 'parallel_all'],
        operators: ['>='],
        default_reminder_hours: 4,
    },
};

describe('ApprovalChains editor', () => {
    beforeEach(() => {
        get.mockReset();
        get.mockResolvedValue({ data: payload });
    });

    it('closes without crashing the page (editing is cleared while the modal still renders)', async () => {
        render(<MemoryRouter><ApprovalChains /></MemoryRouter>);

        fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
        expect(await screen.findByText('Step 1')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.getByRole('heading', { name: 'Approval chains' })).toBeInTheDocument();
        expect(screen.queryByText('Step 1')).toBeNull();
    });
});
