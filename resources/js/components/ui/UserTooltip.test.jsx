import { act, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserTooltip from './UserTooltip';

// Mock AuthContext
vi.mock('../../context/AuthContext', () => ({
    useAuth: () => ({
        user: { id: 1, name: 'Current User' },
        can: (perm) => perm === 'users.view',
    }),
}));

describe('UserTooltip component', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    it('renders children trigger', () => {
        render(
            <MemoryRouter>
                <UserTooltip user={{ id: 2, name: 'Alice Smith', email: 'alice@example.com' }}>
                    <button type="button">Alice</button>
                </UserTooltip>
            </MemoryRouter>
        );
        expect(screen.getByRole('button', { name: 'Alice' })).toBeInTheDocument();
        expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    });

    it('shows rich tooltip card on hover after delay', () => {
        const { container } = render(
            <MemoryRouter>
                <UserTooltip user={{ id: 2, name: 'Alice Smith', email: 'alice@example.com', role: 'Developer' }}>
                    <span className="trigger">Alice</span>
                </UserTooltip>
            </MemoryRouter>
        );

        const trigger = container.querySelector('.trigger');
        fireEvent.mouseEnter(trigger);

        act(() => {
            vi.advanceTimersByTime(150);
        });

        const tooltip = screen.getByRole('tooltip');
        expect(tooltip).toBeInTheDocument();
        expect(screen.getByText('Alice Smith')).toBeInTheDocument();
        expect(screen.getByText('alice@example.com')).toBeInTheDocument();
        expect(screen.getByText(/developer/i)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /view profile/i })).toHaveAttribute('href', '/users/2');
    });

    it('hides tooltip on Escape key', () => {
        const { container } = render(
            <MemoryRouter>
                <UserTooltip user={{ id: 3, name: 'Bob Jones' }}>
                    <span className="trigger">Bob</span>
                </UserTooltip>
            </MemoryRouter>
        );

        const trigger = container.querySelector('.trigger');
        fireEvent.mouseEnter(trigger);
        act(() => {
            vi.advanceTimersByTime(150);
        });

        expect(screen.getByRole('tooltip')).toBeInTheDocument();

        fireEvent.keyDown(trigger, { key: 'Escape' });
        expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    });
});
