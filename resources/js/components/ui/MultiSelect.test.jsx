import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import MultiSelect from './MultiSelect';

describe('MultiSelect component', () => {
    const options = [
        { value: 'admin', label: 'Administrator', description: 'Full access' },
        { value: 'manager', label: 'Manager', description: 'Project manager' },
        { value: 'viewer', label: 'Viewer', description: 'Read only' },
    ];

    it('renders label, placeholder, and quick-pick pills', () => {
        render(
            <MultiSelect
                label="Assigned Roles"
                options={options}
                value={[]}
                onChange={() => {}}
                placeholder="Choose roles..."
            />
        );

        expect(screen.getByText('Assigned Roles')).toBeInTheDocument();
        expect(screen.getByText('Choose roles...')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /administrator/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /manager/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /viewer/i })).toBeInTheDocument();
    });

    it('toggles selection when quick-add pill is clicked', () => {
        const handleChange = vi.fn();
        render(
            <MultiSelect
                label="Roles"
                options={options}
                value={[]}
                onChange={handleChange}
            />
        );

        const adminPill = screen.getByRole('button', { name: /administrator/i });
        fireEvent.click(adminPill);

        expect(handleChange).toHaveBeenCalledWith(['admin']);
    });

    it('opens dropdown, searches, and selects an option', () => {
        const handleChange = vi.fn();
        render(
            <MultiSelect
                label="Roles"
                options={options}
                value={['admin']}
                onChange={handleChange}
                placeholder="Choose roles..."
            />
        );

        // Open dropdown
        const trigger = screen.getByRole('combobox');
        fireEvent.click(trigger);

        expect(screen.getByRole('listbox')).toBeInTheDocument();

        // Click manager option in dropdown
        const managerOption = screen.getByRole('option', { name: /manager/i });
        fireEvent.click(managerOption);

        expect(handleChange).toHaveBeenCalledWith(['admin', 'manager']);
    });

    it('removes option when remove tag button is clicked', () => {
        const handleChange = vi.fn();
        render(
            <MultiSelect
                label="Roles"
                options={options}
                value={['admin', 'manager']}
                onChange={handleChange}
            />
        );

        const removeBtn = screen.getByRole('button', { name: 'Remove Administrator' });
        fireEvent.click(removeBtn);

        expect(handleChange).toHaveBeenCalledWith(['manager']);
    });

    it('clears all options when Clear all is clicked', () => {
        const handleChange = vi.fn();
        render(
            <MultiSelect
                label="Roles"
                options={options}
                value={['admin']}
                onChange={handleChange}
            />
        );

        const clearBtn = screen.getByRole('button', { name: 'Clear all' });
        fireEvent.click(clearBtn);

        expect(handleChange).toHaveBeenCalledWith([]);
    });
});
