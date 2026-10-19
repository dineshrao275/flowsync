import { describe, expect, it } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import Menu from './Menu';
import ContentSkeleton from './ContentSkeleton';
import '../../i18n';

describe('Menu', () => {
    it('renders its panel in document.body so an overflow-hidden table cannot clip it', () => {
        render(
            <div style={{ overflow: 'hidden' }} data-testid="clip">
                <Menu label="Actions" trigger={(p) => <button {...p}>open</button>} items={[{ key: 'a', label: 'Alpha', onSelect() {} }]} />
            </div>,
        );
        fireEvent.click(screen.getByText('open'));
        const item = screen.getByRole('menuitem', { name: 'Alpha' });
        expect(screen.getByTestId('clip').contains(item)).toBe(false);
        expect(document.body.contains(item)).toBe(true);
    });
});

describe('ContentSkeleton', () => {
    it('announces a single loading status', () => {
        render(<ContentSkeleton />);
        expect(screen.getAllByRole('status')).toHaveLength(1);
    });
});
