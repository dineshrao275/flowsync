import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConfirmProvider } from '../../components/ui/ConfirmDialog';
import Resignation from './Resignation';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a) },
    fieldErrors: () => ({}),
}));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => false }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const pending = { id: 1, status: 'pending', requested_last_day: '2027-01-28', reason: 'moving', decision_note: null };

describe('Resignation withdraw confirmation', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockResolvedValue({ data: { mine: [pending], to_decide: [], all: null } });
        post.mockResolvedValue({ data: {} });
    });

    it('confirms with "Withdraw", not the generic danger label "Delete"', async () => {
        render(<MemoryRouter><ConfirmProvider><Resignation /></ConfirmProvider></MemoryRouter>);

        fireEvent.click(await screen.findByRole('button', { name: 'Withdraw' }));
        const dialog = await screen.findByRole('dialog');

        expect(within(dialog).queryByRole('button', { name: 'Delete' })).toBeNull();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Withdraw' }));
        await vi.waitFor(() => expect(post).toHaveBeenCalledWith('/hrms/resignations/1/withdraw', {}));
    });
});
