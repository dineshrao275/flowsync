import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import MediaPicker from './MediaPicker';

vi.mock('../../services/api', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
    fieldErrors: () => ({}),
}));

vi.mock('../../context/ToastContext', () => ({
    useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }),
}));

import api from '../../services/api';

const rows = [
    { id: 1, url: '/storage/media/a.png', original_name: 'a.png', alt: 'Alpha', width: 100, height: 100, size: 1234 },
    { id: 2, url: '/storage/media/b.png', original_name: 'b.png', alt: '', width: 100, height: 100, size: 1234 },
];

function mockList() {
    api.get.mockResolvedValue({ data: { media: rows, pagination: { current_page: 1, last_page: 1, per_page: 12, total: 2 } } });
}

describe('MediaPicker', () => {
    it('shows a placeholder without a value and the thumbnail with one', () => {
        const onSelect = vi.fn();
        const { rerender } = render(<MediaPicker value={null} onSelect={onSelect} />);
        expect(screen.getByText('No image')).toBeTruthy();

        rerender(<MediaPicker value={rows[0]} onSelect={onSelect} />);
        expect(screen.getByAltText('Alpha')).toBeTruthy();
    });

    it('browses the library and returns the chosen image', async () => {
        mockList();
        const onSelect = vi.fn();
        render(<MediaPicker value={null} onSelect={onSelect} />);

        fireEvent.click(screen.getByText('Choose…'));
        await waitFor(() => expect(screen.getByAltText('Alpha')).toBeTruthy());

        fireEvent.click(screen.getByAltText('Alpha'));
        expect(onSelect).toHaveBeenCalledWith(rows[0]);
    });

    it('clears the selection', () => {
        const onSelect = vi.fn();
        render(<MediaPicker value={rows[0]} onSelect={onSelect} />);
        fireEvent.click(screen.getByText('Clear'));
        expect(onSelect).toHaveBeenCalledWith(null);
    });
});
