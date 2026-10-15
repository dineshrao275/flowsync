import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Timestamp from './Timestamp';

describe('Timestamp component', () => {
    it('renders fallback when date is null or invalid', () => {
        const { rerender } = render(<Timestamp date={null} fallback="No date" />);
        expect(screen.getByText('No date')).toBeInTheDocument();

        rerender(<Timestamp date="invalid-date" fallback="Invalid" />);
        expect(screen.getByText('Invalid')).toBeInTheDocument();
    });

    it('renders human-readable relative time for recent timestamp', () => {
        const now = new Date();
        const tenMinsAgo = new Date(now.getTime() - 10 * 60 * 1000).toISOString();

        render(<Timestamp date={tenMinsAgo} />);
        expect(screen.getByText(/10 minutes ago/i)).toBeInTheDocument();
    });

    it('shows short relative time when short prop is true', () => {
        const now = new Date();
        const tenMinsAgo = new Date(now.getTime() - 10 * 60 * 1000).toISOString();

        render(<Timestamp date={tenMinsAgo} short />);
        expect(screen.getByText('10m ago')).toBeInTheDocument();
    });

    it('displays full date and time tooltip on hover', () => {
        const iso = '2026-10-12T14:30:00Z';
        const { container } = render(<Timestamp date={iso} />);

        const tooltip = screen.getByRole('tooltip', { hidden: true });
        expect(tooltip).toBeInTheDocument();
        expect(tooltip).toHaveTextContent(/2026/);
        expect(tooltip).toHaveClass('invisible');

        const wrapper = container.querySelector('.group');
        fireEvent.mouseEnter(wrapper);

        expect(tooltip).toHaveClass('visible');
    });

    it('supports custom prefix and suffix', () => {
        const now = new Date();
        const oneHourAgo = new Date(now.getTime() - 60 * 60 * 1000).toISOString();

        render(<Timestamp date={oneHourAgo} prefix="Updated " suffix="." short />);
        expect(screen.getByText('Updated 1h ago.')).toBeInTheDocument();
    });
});
