import { fireEvent, render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import useGlobalShortcuts from './useGlobalShortcuts';

function Harness(props) {
    useGlobalShortcuts(props);
    return <input aria-label="field" />;
}

describe('useGlobalShortcuts', () => {
    it('opens search on / and help on ?', () => {
        const onSearch = vi.fn();
        const onHelp = vi.fn();
        render(<Harness onSearch={onSearch} onHelp={onHelp} />);

        fireEvent.keyDown(document.body, { key: '/' });
        fireEvent.keyDown(document.body, { key: '?' });

        expect(onSearch).toHaveBeenCalledTimes(1);
        expect(onHelp).toHaveBeenCalledTimes(1);
    });

    it('supports g-then-key navigation and ignores unknown keys', () => {
        const dashboard = vi.fn();
        render(<Harness go={{ d: dashboard }} />);

        fireEvent.keyDown(document.body, { key: 'g' });
        fireEvent.keyDown(document.body, { key: 'x' });
        expect(dashboard).not.toHaveBeenCalled();

        fireEvent.keyDown(document.body, { key: 'g' });
        fireEvent.keyDown(document.body, { key: 'd' });
        expect(dashboard).toHaveBeenCalledTimes(1);
    });

    it('does nothing while typing in a field, with a modifier, or inside a dialog', () => {
        const onSearch = vi.fn();
        const { getByLabelText } = render(<Harness onSearch={onSearch} />);

        fireEvent.keyDown(getByLabelText('field'), { key: '/' });
        fireEvent.keyDown(document.body, { key: '/', ctrlKey: true });
        expect(onSearch).not.toHaveBeenCalled();

        const dialog = document.createElement('div');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        document.body.appendChild(dialog);
        fireEvent.keyDown(document.body, { key: '/' });
        expect(onSearch).not.toHaveBeenCalled();
        dialog.remove();
    });
});
