import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ThemeSettingsDrawer from './ThemeSettingsDrawer';
import { DEFAULT_THEME } from '../theme';

vi.mock('../context/ThemeContext', () => ({
    useTheme: () => ({ draft: DEFAULT_THEME, preview: () => {}, previewFull: () => {}, save: async () => ({ ok: true }), reset: () => {} }),
}));
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: () => {}, error: () => {}, info: () => {} }) }));

describe('ThemeSettingsDrawer', () => {
    it('is hidden from assistive tech and the tab order while closed', () => {
        const { container } = render(<ThemeSettingsDrawer open={false} onClose={() => {}} />);
        const drawer = container.querySelector('aside');

        expect(drawer).toHaveAttribute('aria-hidden', 'true');
        expect(drawer).toHaveAttribute('inert');
        // Not reachable by role either: a closed panel must not announce itself as a dialog.
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('is a modal dialog once open', () => {
        render(<ThemeSettingsDrawer open onClose={() => {}} />);
        const dialog = screen.getByRole('dialog');

        expect(dialog).not.toHaveAttribute('aria-hidden', 'true');
        expect(dialog).not.toHaveAttribute('inert');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
    });
});
