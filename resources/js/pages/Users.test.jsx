import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Users from './Users';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock('../services/api', () => ({
    default: { get: (...a) => get(...a), put: vi.fn(), post: (...a) => post(...a), delete: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../context/AuthContext', () => ({
    useAuth: () => ({ can: () => true, user: { id: 1, name: 'Admin User' } }),
}));
vi.mock('../context/ToastContext', () => ({
    useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));
vi.mock('../components/ui/ConfirmDialog', () => ({
    useConfirm: () => vi.fn().mockResolvedValue(true),
}));

const mockRoles = [
    { id: 1, name: 'Admin', slug: 'admin' },
    { id: 2, name: 'Editor', slug: 'editor' },
    { id: 3, name: 'Viewer', slug: 'viewer' },
];

const mockUsers = [
    { id: 1, name: 'Admin User', email: 'admin@flowsync.test', roles: ['admin'], is_default: true },
    { id: 2, name: 'Editor User', email: 'editor@flowsync.test', roles: ['editor'], is_default: false },
];

describe('Users page', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockImplementation((url) => {
            if (url === '/roles') {
                return Promise.resolve({ data: { roles: mockRoles } });
            }
            if (url === '/users') {
                return Promise.resolve({
                    data: {
                        users: mockUsers,
                        pagination: {
                            current_page: 1,
                            last_page: 2,
                            per_page: 2,
                            total: 4,
                        },
                    },
                });
            }
            return Promise.resolve({ data: {} });
        });
    });

    it('renders search filter, role filter, type filter and pagination', async () => {
        render(
            <MemoryRouter>
                <Users />
            </MemoryRouter>,
        );

        await screen.findByText('Admin User');
        expect(screen.getByText('Editor User')).toBeInTheDocument();

        // Search input
        const searchInput = screen.getByPlaceholderText('Search by name or email…');
        expect(searchInput).toBeInTheDocument();

        // Role select
        const roleSelect = screen.getByRole('combobox', { name: 'All roles' });
        expect(roleSelect).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: 'All users' })).toBeInTheDocument();

        // Pagination buttons
        expect(screen.getByRole('button', { name: 'Next' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Prev' })).toBeDisabled();
    });

    it('changes page when pagination next button is clicked', async () => {
        render(
            <MemoryRouter>
                <Users />
            </MemoryRouter>,
        );

        await screen.findByText('Admin User');
        const nextButton = screen.getByRole('button', { name: 'Next' });
        expect(nextButton).not.toBeDisabled();

        fireEvent.click(nextButton);

        await waitFor(() => {
            expect(get).toHaveBeenCalledWith('/users', expect.objectContaining({
                params: expect.objectContaining({ page: 2 }),
            }));
        });
    });

    it('blocks adding a user until the passwords match', async () => {
        post.mockResolvedValue({ data: { user: { name: 'New Person' } } });

        render(
            <MemoryRouter>
                <Users />
            </MemoryRouter>,
        );

        await screen.findByText('Admin User');
        fireEvent.click(screen.getByRole('button', { name: 'Add user' }));

        fireEvent.change(screen.getByLabelText('Full name'), { target: { value: 'New Person' } });
        fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'new@example.test' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret123' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'secret124' } });

        expect(screen.getByText('Passwords do not match.')).toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: 'Add user' }).at(-1));

        await waitFor(() => expect(post).not.toHaveBeenCalled());

        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'secret123' } });
        expect(screen.getByText('Passwords match.')).toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: 'Add user' }).at(-1));

        await waitFor(() =>
            expect(post).toHaveBeenCalledWith('/users', expect.objectContaining({
                password: 'secret123',
                password_confirmation: 'secret123',
            })),
        );
    });

    it('filters users when role is selected', async () => {
        render(
            <MemoryRouter>
                <Users />
            </MemoryRouter>,
        );

        await screen.findByText('Admin User');
        const selects = screen.getAllByRole('combobox');
        const roleSelect = selects[0];

        fireEvent.change(roleSelect, { target: { value: 'editor' } });

        await waitFor(() => {
            expect(get).toHaveBeenCalledWith('/users', expect.objectContaining({
                params: expect.objectContaining({ role: 'editor', page: 1 }),
            }));
        });
    });
});
