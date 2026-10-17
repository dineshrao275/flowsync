import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Modal from './Modal';
import Drawer from './Drawer';

function Host({ Component }) {
    const [open, setOpen] = useState(true);
    const [value, setValue] = useState('');

    // onClose is a fresh arrow on every render, exactly like most call sites.
    return (
        <Component open={open} onClose={() => setOpen(false)} title="Edit">
            <input aria-label="Name" value={value} onChange={(e) => setValue(e.target.value)} />
        </Component>
    );
}

describe.each([['Modal', Modal], ['Drawer', Drawer]])('%s focus handling', (_name, Component) => {
    it('keeps focus in the field while typing, even though onClose changes identity every render', () => {
        render(<Host Component={Component} />);

        const input = screen.getByLabelText('Name');
        expect(input).toHaveFocus(); // lands on the first field, not the close button

        // Each change re-renders the host, handing the dialog a brand-new onClose.
        for (const text of ['h', 'he', 'hel', 'hell', 'hello']) {
            fireEvent.change(input, { target: { value: text } });
            expect(input).toHaveValue(text);
            expect(input).toHaveFocus();
        }
    });

    it('still closes on Escape', () => {
        render(<Host Component={Component} />);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByLabelText('Name')).not.toBeInTheDocument();
    });
});
