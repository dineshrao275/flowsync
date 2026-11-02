import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Expenses from './Expenses';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a), put: vi.fn(), delete: vi.fn() },
    fieldErrors: () => ({}),
}));
// A chain approver (a manager) holds no expense permission: only the step flag lets them act.
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => false, user: {} }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../components/ui/ConfirmDialog', () => ({ useConfirm: () => vi.fn() }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));
vi.mock('../../components/hrms/RecordComments', () => ({ default: () => null }));
vi.mock('../../components/ui/ListSavedFilters', () => ({ default: () => null }));

const claim = (over = {}) => ({
    id: 5, employee_id: 2, claim_number: 'EXP-2026-000005', period_year: 2026, period_month: 8,
    total_amount: '120.00', approved_amount: null, status: 'submitted', can_act_step: true, items: [], ...over,
});

describe('Expenses page for a chain approver without the approve permission', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        post.mockResolvedValue({ data: {} });
        get.mockImplementation((url) => {
            if (url === '/hrms/expenses/claims/5') return Promise.resolve({ data: { claim: claim() } });
            if (url.includes('/hrms/expenses/claims')) return Promise.resolve({ data: { claims: [claim()] } });
            return Promise.resolve({ data: { categories: [], employees: [] } });
        });
    });

    it('offers their own step and posts it, but never the priced decision form', async () => {
        render(<MemoryRouter><Expenses /></MemoryRouter>);

        fireEvent.click(await screen.findByRole('button', { name: 'Decide' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Clear my step' }));

        await waitFor(() => expect(post).toHaveBeenCalledWith('/hrms/expenses/claims/5/approve-step', { note: null }));
        expect(screen.queryByRole('button', { name: 'Record decision' })).not.toBeInTheDocument();
    });

    it('shows no action for a claim whose step they cannot act on', async () => {
        get.mockImplementation((url) => (url.includes('/hrms/expenses/claims')
            ? Promise.resolve({ data: { claims: [claim({ can_act_step: false })] } })
            : Promise.resolve({ data: { categories: [], employees: [] } })));
        render(<MemoryRouter><Expenses /></MemoryRouter>);

        await screen.findByText('EXP-2026-000005');
        expect(screen.queryByRole('button', { name: 'Decide' })).not.toBeInTheDocument();
    });
});
