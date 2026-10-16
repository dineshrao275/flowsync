import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Roles from './Roles';

const get = vi.fn();

vi.mock('../services/api', () => ({
    default: { get: (...a) => get(...a), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ can: () => true }) }));
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../components/ui/ConfirmDialog', () => ({ useConfirm: () => vi.fn() }));

const permissions = Array.from({ length: 10 }, (_, i) => ({ id: i + 1, name: `Perm ${i + 1}`, slug: `area${i % 8}.thing.view${i}`, product: 'tms' }));
const roles = [
    { id: 1, name: 'admin', slug: 'admin', is_system: true, users_count: 1, permissions },
    { id: 2, name: 'viewer', slug: 'viewer', is_system: false, users_count: 0, permissions: permissions.slice(0, 2) },
    { id: 3, name: 'empty', slug: 'empty', is_system: false, users_count: 3, permissions: [] },
];

describe('Roles page', () => {
    beforeEach(() => { get.mockReset(); get.mockResolvedValue({ data: { roles, permissions } }); });

    it('shows each role as a card with a summary instead of one giant permission cell', async () => {
        render(<MemoryRouter><Roles /></MemoryRouter>);

        const admin = (await screen.findByRole('heading', { name: 'admin' })).closest('div.rounded-xl');
        expect(within(admin).getByText('All permissions')).toBeInTheDocument();
        expect(within(admin).getByText('Built-in')).toBeInTheDocument();
        expect(within(admin).getByText('1 member')).toBeInTheDocument();
        // The 10 permission names are NOT dumped in a row; only domain chips show.
        expect(within(admin).queryByText('Perm 1')).not.toBeInTheDocument();
        // More domains than the chip limit collapse into "+N more".
        expect(within(admin).getByText(/\+\d+ more/)).toBeInTheDocument();

        const viewer = screen.getByRole('heading', { name: 'viewer' }).closest('div.rounded-xl');
        expect(within(viewer).getByText('2 of 10 permissions')).toBeInTheDocument();
        expect(within(screen.getByRole('heading', { name: 'empty' }).closest('div.rounded-xl')).getByText('No permissions')).toBeInTheDocument();
    });

    it('expands to the grouped full list and back', async () => {
        render(<MemoryRouter><Roles /></MemoryRouter>);
        const viewer = (await screen.findByRole('heading', { name: 'viewer' })).closest('div.rounded-xl');

        fireEvent.click(within(viewer).getByRole('button', { name: 'Show all permissions' }));
        expect(within(viewer).getByText('Perm 1')).toBeInTheDocument();

        fireEvent.click(within(viewer).getByRole('button', { name: 'Show summary' }));
        await waitFor(() => expect(within(viewer).queryByText('Perm 1')).not.toBeInTheDocument());
    });

    it('opens the grouped editor in the card, with save and cancel', async () => {
        render(<MemoryRouter><Roles /></MemoryRouter>);
        const viewer = (await screen.findByRole('heading', { name: 'viewer' })).closest('div.rounded-xl');

        fireEvent.click(within(viewer).getByRole('button', { name: 'Edit permissions' }));
        expect(within(viewer).getByRole('searchbox')).toBeInTheDocument();
        expect(within(viewer).getByRole('button', { name: 'Save role' })).toBeInTheDocument();
        expect(within(viewer).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });
});
