import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PasswordMatchHint from './PasswordMatchHint';

describe('PasswordMatchHint', () => {
    it('stays hidden until the confirmation has content', () => {
        const { rerender } = render(<PasswordMatchHint password="" confirmation="" />);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        rerender(<PasswordMatchHint password="secret123" confirmation="" />);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('announces a match', () => {
        render(<PasswordMatchHint password="secret123" confirmation="secret123" />);

        const status = screen.getByRole('status');
        expect(status).toHaveTextContent('Passwords match.');
        expect(status).toHaveClass('text-success');
        expect(status).toHaveAttribute('aria-live', 'polite');
    });

    it('announces a mismatch', () => {
        render(<PasswordMatchHint password="secret123" confirmation="secret124" />);

        const status = screen.getByRole('status');
        expect(status).toHaveTextContent('Passwords do not match.');
        expect(status).toHaveClass('text-danger-fg');
    });
});
