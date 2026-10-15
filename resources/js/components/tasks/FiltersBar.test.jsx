import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import FiltersBar from './FiltersBar';

const options = {
    statuses: [{ id: 1, name: 'Backlog' }],
    priorities: [{ id: 2, name: 'High' }],
    assignees: [{ id: 3, name: 'Ava' }],
};

describe('FiltersBar', () => {
    it('pairs every visible label with its control', () => {
        render(<FiltersBar filters={{}} options={options} onChange={() => {}} />);

        for (const name of ['Search', 'Status', 'Priority', 'Assignee']) {
            const label = screen.getByText(name);
            const control = label.htmlFor
                ? document.getElementById(label.htmlFor)
                : screen.getByLabelText(name);

            expect(control).not.toBeNull();
        }
    });

    it('clear resets every filter', () => {
        const onChange = vi.fn();
        render(<FiltersBar filters={{ q: 'risk' }} options={options} onChange={onChange} />);

        fireEvent.click(screen.getByRole('button', { name: 'Clear' }));

        expect(onChange).toHaveBeenCalledWith({});
    });
});
