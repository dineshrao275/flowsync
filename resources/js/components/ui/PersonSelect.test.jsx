import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import PersonSelect from './PersonSelect';
import '../../i18n';

const people = [
    { id: 1, name: 'Ada Lovelace', email: 'ada@x.test' },
    { id: 2, name: 'Grace Hopper' },
];

describe('PersonSelect', () => {
    it('lists avatar cards, filters by typing, and reports the id like a <select> would', () => {
        const onChange = vi.fn();
        render(<PersonSelect label="Person" people={people} value="" onChange={onChange} />);
        const input = screen.getByRole('combobox');
        fireEvent.focus(input);
        expect(screen.getByText('AL')).toBeInTheDocument();
        expect(screen.getByText('ada@x.test')).toBeInTheDocument();
        fireEvent.change(input, { target: { value: 'grace' } });
        expect(screen.queryByText('Ada Lovelace')).toBeNull();
        fireEvent.click(screen.getByRole('option', { name: /Grace Hopper/ }));
        expect(onChange).toHaveBeenCalledWith({ target: { value: '2' } });
    });

    it('shows the chosen person as a card and clears with Change', () => {
        const onChange = vi.fn();
        render(<PersonSelect people={people} value="1" onChange={onChange} />);
        expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /change/i }));
        expect(onChange).toHaveBeenCalledWith({ target: { value: '' } });
    });

    it('renders dropdown with z-[9999] so it sits above modals', () => {
        render(<PersonSelect people={people} value="" onChange={() => {}} />);
        const input = screen.getByRole('combobox');
        fireEvent.focus(input);
        const listbox = screen.getByRole('listbox');
        expect(listbox.className).toContain('z-[9999]');
    });
});
