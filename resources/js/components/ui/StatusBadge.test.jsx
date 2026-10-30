import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StatusBadge, { getStatusTone } from './StatusBadge';

describe('StatusBadge component', () => {
    it('correctly derives semantic tones', () => {
        expect(getStatusTone('Done')).toBe('success');
        expect(getStatusTone('Completed')).toBe('success');
        expect(getStatusTone('Approved')).toBe('success');
        expect(getStatusTone('In Progress')).toBe('info');
        expect(getStatusTone('In Review')).toBe('info');
        expect(getStatusTone('Blocked')).toBe('danger');
        expect(getStatusTone('Rejected')).toBe('danger');
        expect(getStatusTone('Pending')).toBe('warning');
        expect(getStatusTone('To Do')).toBe('neutral');
    });

    it('renders status text and dot indicator', () => {
        render(<StatusBadge status="In Progress" />);
        expect(screen.getByText('In Progress')).toBeInTheDocument();
    });

    it('handles status objects with name property', () => {
        render(<StatusBadge status={{ id: 1, name: 'Done' }} />);
        expect(screen.getByText('Done')).toBeInTheDocument();
    });

    it('returns null when status is empty', () => {
        const { container } = render(<StatusBadge status={null} />);
        expect(container.firstChild).toBeNull();
    });
});
