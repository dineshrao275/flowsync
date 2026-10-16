import { fireEvent, render, screen, within } from '@testing-library/react';
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

const permissions = Array.from({ length: 10 }, (_, i) => ({ id: i + 1, name: `Perm ${i + 1}`, slug: `area${i % 3}.thing.view${i}`, product: 'tms' }));
const roles = [
    { id: 1, name: 'admin', slug: 'admin', is_system: true, users_count: 1, permissions },
    { id: 2, name: 'viewer', slug: 'viewer', is_system: false, users_count: 0, permissions: permissions.slice(0, 2) },
    { id: 3, name: 'empty', slug: 'empty', is_system: false, users_count: 3, permissions: [] },
];

const rowOf = (name) => screen.getAllByRole('row').find((row) => row.querySelector('td')?.textContent === name);

describe('Roles page', () => {
    beforeEach(() => { get.mockReset(); get.mockResolvedValue({ data: { roles, permissions } }); });

    it('is a simple table with a one-line permission summary per role', async () => {
        render(<MemoryRouter><Roles /></MemoryRouter>);

        await screen.findByRole('table');
        expect(within(rowOf('admin')).getByText('All permissions')).toBeInTheDocument();
        expect(within(rowOf('viewer')).getByText('2 of 10 permissions')).toBeInTheDocument();
        expect(within(rowOf('empty')).getByText('No permissions')).toBeInTheDocument();
        // No permission names are dumped into the row.
        expect(screen.queryByText(/Perm 1/)).not.toBeInTheDocument();
    });

    it('View opens the role\'s full permission details in a modal', async () => {
        render(<MemoryRouter><Roles /></MemoryRouter>);
        await screen.findByRole('table');

        fireEvent.click(within(rowOf('viewer')).getByRole('button', { name: 'View' }));

        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('Perm 1')).toBeInTheDocument();
        expect(within(dialog).getByText('Perm 2')).toBeInTheDocument();
        expect(within(dialog).queryByText('Perm 3')).not.toBeInTheDocument();
        // Read-only: checkboxes are disabled and there is no save button.
        expect(within(dialog).getAllByRole('checkbox').every((box) => box.disabled)).toBe(true);
        expect(within(dialog).queryByRole('button', { name: 'Save role' })).not.toBeInTheDocument();
    });

    it('Edit permissions opens the editable picker in a modal; built-in roles cannot be edited or deleted', async () => {
        render(<MemoryRouter><Roles /></MemoryRouter>);
        await screen.findByRole('table');

        expect(within(rowOf('admin')).queryByRole('button', { name: 'Edit permissions' })).not.toBeInTheDocument();
        expect(within(rowOf('admin')).queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();

        fireEvent.click(within(rowOf('viewer')).getByRole('button', { name: 'Edit permissions' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByRole('button', { name: 'Save role' })).toBeInTheDocument();
        expect(within(dialog).getAllByRole('checkbox').some((box) => !box.disabled)).toBe(true);
    });
});
