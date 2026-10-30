import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Drawer from './Drawer';

describe('Drawer', () => {
    it('does not render when open is false', () => {
        render(
            <Drawer open={false} onClose={() => {}} title="Test Drawer">
                <p>Hidden Content</p>
            </Drawer>,
        );
        expect(screen.queryByText('Test Drawer')).toBeNull();
        expect(screen.queryByText('Hidden Content')).toBeNull();
    });

    it('renders content and close button when open', () => {
        const onClose = vi.fn();
        render(
            <Drawer open={true} onClose={onClose} title="Task Detail">
                <p>Task Content</p>
            </Drawer>,
        );
        expect(screen.getByText('Task Detail')).toBeInTheDocument();
        expect(screen.getByText('Task Content')).toBeInTheDocument();

        const closeBtn = screen.getByRole('button', { name: /close/i });
        fireEvent.click(closeBtn);
        expect(onClose).toHaveBeenCalled();
    });

    it('shows fullscreen toggle button only when allowFullscreen is true', () => {
        const { rerender } = render(
            <Drawer open={true} onClose={() => {}} allowFullscreen={false} title="No Fullscreen">
                <p>Content</p>
            </Drawer>,
        );
        expect(screen.queryByRole('button', { name: /full size/i })).toBeNull();

        rerender(
            <Drawer open={true} onClose={() => {}} allowFullscreen={true} title="With Fullscreen">
                <p>Content</p>
            </Drawer>,
        );
        expect(screen.getByRole('button', { name: /full size/i })).toBeInTheDocument();
    });

    it('toggles between full size and restore size on click', () => {
        render(
            <Drawer open={true} onClose={() => {}} allowFullscreen={true} title="Task Detail">
                <p>Content</p>
            </Drawer>,
        );

        const toggleBtn = screen.getByRole('button', { name: /full size/i });
        expect(toggleBtn).toBeInTheDocument();

        // Click to expand to full size
        fireEvent.click(toggleBtn);
        expect(screen.getByRole('button', { name: /restore size/i })).toBeInTheDocument();

        // Click again to restore
        fireEvent.click(screen.getByRole('button', { name: /restore size/i }));
        expect(screen.getByRole('button', { name: /full size/i })).toBeInTheDocument();
    });

    it('supports controlled fullscreen and onToggleFullscreen', () => {
        const onToggle = vi.fn();
        const { rerender } = render(
            <Drawer
                open={true}
                onClose={() => {}}
                allowFullscreen={true}
                fullscreen={false}
                onToggleFullscreen={onToggle}
                title="Controlled Drawer"
            >
                <p>Content</p>
            </Drawer>,
        );

        const toggleBtn = screen.getByRole('button', { name: /full size/i });
        fireEvent.click(toggleBtn);
        expect(onToggle).toHaveBeenCalledWith(true);

        rerender(
            <Drawer
                open={true}
                onClose={() => {}}
                allowFullscreen={true}
                fullscreen={true}
                onToggleFullscreen={onToggle}
                title="Controlled Drawer"
            >
                <p>Content</p>
            </Drawer>,
        );
        expect(screen.getByRole('button', { name: /restore size/i })).toBeInTheDocument();
    });
});
