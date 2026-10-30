import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ResetPassword from './ResetPassword';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('../../services/api', () => ({
    default: { post: (...args) => post(...args) },
    fieldErrors: () => ({}),
}));

function renderPage() {
    return render(
        <MemoryRouter initialEntries={['/reset-password?token=tok-123&email=user%40example.test']}>
            <ResetPassword />
        </MemoryRouter>,
    );
}

beforeEach(() => {
    post.mockReset();
    post.mockResolvedValue({ data: {} });
});

describe('ResetPassword', () => {
    it('blocks submitting until the passwords match', async () => {
        renderPage();

        fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'secret123' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'secret124' } });

        expect(screen.getByText('Passwords do not match.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Reset password' }));

        await waitFor(() => expect(post).not.toHaveBeenCalled());

        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'secret123' } });
        expect(screen.getByText('Passwords match.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Reset password' }));

        await waitFor(() =>
            expect(post).toHaveBeenCalledWith('/auth/reset-password', {
                email: 'user@example.test',
                token: 'tok-123',
                password: 'secret123',
                password_confirmation: 'secret123',
            }),
        );
    });

    it('lets the user reveal both password fields', () => {
        renderPage();

        const [newPassword, confirmation] = screen.getAllByLabelText(/password/i);
        expect(newPassword).toHaveAttribute('type', 'password');
        expect(confirmation).toHaveAttribute('type', 'password');

        fireEvent.click(screen.getAllByRole('button', { name: 'Show password' })[0]);
        expect(newPassword).toHaveAttribute('type', 'text');
        expect(confirmation).toHaveAttribute('type', 'password');

        fireEvent.click(screen.getAllByRole('button', { name: 'Show password' })[0]);
        expect(confirmation).toHaveAttribute('type', 'text');
    });
});
