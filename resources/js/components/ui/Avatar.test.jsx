import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import Avatar, { initialsOf } from './Avatar';
import ProgressBar from './ProgressBar';

describe('Avatar', () => {
    it('uses the first letters of the first and last name', () => {
        expect(initialsOf('Ada Lovelace')).toBe('AL');
        expect(initialsOf('Ada Augusta King Lovelace')).toBe('AL');
        expect(initialsOf('ada')).toBe('A');
        expect(initialsOf('')).toBe('?');
    });

    it('shows the photo when given one, initials otherwise', () => {
        const { container, rerender } = render(<Avatar name="Ada Lovelace" photoUrl="/p.png" />);
        expect(container.querySelector('img')).toHaveAttribute('src', '/p.png');
        rerender(<Avatar name="Ada Lovelace" />);
        expect(screen.getByText('AL')).toBeInTheDocument();
    });
});

describe('ProgressBar', () => {
    it('reports the rounded percentage', () => {
        render(<ProgressBar value={1} max={3} label="Progress" />);
        expect(screen.getByRole('progressbar', { name: 'Progress' })).toHaveAttribute('aria-valuenow', '33');
    });
});
