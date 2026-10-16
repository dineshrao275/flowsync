import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Alert from './Alert';
import Badge from './Badge';
import DataTable from './DataTable';
import ErrorState from './ErrorState';
import FormField from './FormField';
import Input from './Input';
import Menu from './Menu';
import Modal from './Modal';
import PageHeader from './PageHeader';
import StatusPill from './StatusPill';
import Switch from './Switch';
import TableSkeleton from './TableSkeleton';
import Tabs from './Tabs';
import Tooltip from './Tooltip';

describe('Tabs a11y', () => {
    const tabs = [{ key: 'a', label: 'Alpha' }, { key: 'b', label: 'Beta' }, { key: 'c', label: 'Gamma', count: 4 }];

    function Harness() {
        const [active, setActive] = useState('a');
        return <Tabs tabs={tabs} active={active} onChange={setActive} label="Sections" />;
    }

    it('exposes the tabs pattern with roving tabindex', () => {
        render(<Harness />);

        expect(screen.getByRole('tablist', { name: 'Sections' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Alpha' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tab', { name: 'Alpha' })).toHaveAttribute('tabindex', '0');
        expect(screen.getByRole('tab', { name: 'Beta' })).toHaveAttribute('tabindex', '-1');
    });

    it('moves with arrow keys, wrapping, and focuses the new tab', () => {
        render(<Harness />);

        fireEvent.keyDown(screen.getByRole('tab', { name: 'Alpha' }), { key: 'ArrowRight' });
        expect(screen.getByRole('tab', { name: 'Beta' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tab', { name: 'Beta' })).toHaveFocus();

        fireEvent.keyDown(screen.getByRole('tab', { name: 'Beta' }), { key: 'End' });
        expect(screen.getByRole('tab', { name: /Gamma/ })).toHaveAttribute('aria-selected', 'true');

        fireEvent.keyDown(screen.getByRole('tab', { name: /Gamma/ }), { key: 'ArrowRight' });
        expect(screen.getByRole('tab', { name: 'Alpha' })).toHaveAttribute('aria-selected', 'true');
    });
});

describe('tones', () => {
    it('Badge uses semantic tone classes', () => {
        render(<Badge tone="success">Paid</Badge>);
        expect(screen.getByText('Paid').className).toContain('text-success');
    });

    it('StatusPill supports tone and falls back to hex colour', () => {
        const { rerender } = render(<StatusPill tone="danger" label="Overdue" />);
        expect(screen.getByText('Overdue').parentElement.className).toContain('text-danger-fg');

        rerender(<StatusPill color="#10b981" label="Done" />);
        expect(screen.getByText('Done').parentElement.style.backgroundColor).not.toBe('');
    });

    it('Alert errors interrupt, others are polite', () => {
        const { rerender } = render(<Alert type="error">Bad</Alert>);
        expect(screen.getByRole('alert')).toHaveTextContent('Bad');

        rerender(<Alert type="warning">Careful</Alert>);
        expect(screen.getByRole('status')).toHaveTextContent('Careful');
    });
});

describe('Modal backdrop', () => {
    it('closes on a backdrop click but not when the press began inside the panel', () => {
        const onClose = vi.fn();
        render(<Modal open onClose={onClose} title="T"><p>body</p></Modal>);

        const dialog = screen.getByRole('dialog');
        const backdrop = dialog.parentElement;

        // text-selection drag: starts in the panel, releases on the backdrop
        fireEvent.mouseDown(dialog);
        fireEvent.click(backdrop);
        expect(onClose).not.toHaveBeenCalled();

        fireEvent.mouseDown(backdrop);
        fireEvent.click(backdrop);
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});

describe('FormField', () => {
    it('wires label, hint, error and required to the control', () => {
        render(
            <FormField label="Email" hint="Work address" error="Required" required>
                <Input />
            </FormField>,
        );

        const input = screen.getByLabelText(/Email/);
        expect(input).toBeRequired();
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(input.getAttribute('aria-describedby')).toContain('-error');
        expect(screen.getByText('Required', { selector: 'p' })).toBeInTheDocument();
    });
});

describe('Switch', () => {
    it('is a switch that reports the toggled value', () => {
        const onChange = vi.fn();
        render(<Switch checked={false} onChange={onChange} label="Notify me" />);

        const control = screen.getByRole('switch', { name: 'Notify me' });
        expect(control).toHaveAttribute('aria-checked', 'false');
        fireEvent.click(control);
        expect(onChange).toHaveBeenCalledWith(true);
    });
});

describe('Menu', () => {
    it('opens, navigates with arrows, activates and closes with Escape', () => {
        const edit = vi.fn();
        render(
            <Menu
                label="Row actions"
                trigger={(props) => <button {...props}>More</button>}
                items={[{ key: 'edit', label: 'Edit', onSelect: edit }, { key: 'del', label: 'Delete', danger: true }]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        const menu = screen.getByRole('menu');
        expect(within(menu).getAllByRole('menuitem')).toHaveLength(2);
        expect(screen.getByRole('menuitem', { name: 'Edit' })).toHaveFocus();

        fireEvent.keyDown(menu, { key: 'ArrowDown' });
        expect(screen.getByRole('menuitem', { name: 'Delete' })).toHaveFocus();

        fireEvent.keyDown(menu, { key: 'Escape' });
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Row actions' })).toHaveFocus();

        fireEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Edit' }));
        expect(edit).toHaveBeenCalled();
    });
});

describe('Tooltip', () => {
    it('shows on focus and hides on Escape', () => {
        render(<Tooltip content="Delete item"><button type="button">x</button></Tooltip>);

        fireEvent.focus(screen.getByRole('button'));
        expect(screen.getByRole('tooltip')).toHaveTextContent('Delete item');
        expect(screen.getByRole('button')).toHaveAttribute('aria-describedby', screen.getByRole('tooltip').id);

        fireEvent.keyDown(screen.getByRole('button'), { key: 'Escape' });
        expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    });
});

describe('PageHeader / ErrorState / TableSkeleton', () => {
    it('renders a page heading (h2 under the Topbar h1) with actions', () => {
        render(<PageHeader title="Users" description="People" actions={<button type="button">Add</button>} />);
        expect(screen.getByRole('heading', { level: 2, name: 'Users' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
    });

    it('ErrorState announces and retries', () => {
        const retry = vi.fn();
        render(<ErrorState onRetry={retry} />);
        expect(screen.getByRole('alert')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(retry).toHaveBeenCalled();
    });

    it('TableSkeleton announces loading once', () => {
        render(<TableSkeleton columns={3} rows={4} />);
        expect(screen.getAllByRole('status')).toHaveLength(1);
    });
});

describe('DataTable', () => {
    const columns = [
        { key: 'name', header: 'Name', sortable: true },
        { key: 'role', header: 'Role' },
    ];
    const rows = [{ id: 1, name: 'Ada', role: 'admin' }, { id: 2, name: 'Bob', role: 'viewer' }];

    it('renders rows, marks the sorted column and reports sort clicks', () => {
        const onSort = vi.fn();
        render(<DataTable columns={columns} rows={rows} sort={{ key: 'name', dir: 'asc' }} onSort={onSort} />);

        const table = screen.getByRole('table');
        expect(within(table).getByText('Ada')).toBeInTheDocument();
        expect(within(table).getByRole('columnheader', { name: /Name/ })).toHaveAttribute('aria-sort', 'ascending');

        fireEvent.click(within(table).getByRole('button', { name: /Name/ }));
        expect(onSort).toHaveBeenCalledWith('name');
    });

    it('supports select-all and per-row selection', () => {
        const onSelectedChange = vi.fn();
        render(<DataTable columns={columns} rows={rows} selectable selected={new Set()} onSelectedChange={onSelectedChange} />);

        fireEvent.click(within(screen.getByRole('table')).getByLabelText('Select all rows'));
        expect([...onSelectedChange.mock.calls[0][0]]).toEqual([1, 2]);
    });

    it('shows skeleton, error with retry, and empty states', () => {
        const retry = vi.fn();
        const { rerender } = render(<DataTable columns={columns} rows={[]} loading />);
        expect(screen.getByRole('status')).toBeInTheDocument();

        rerender(<DataTable columns={columns} rows={[]} error="boom" onRetry={retry} />);
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(retry).toHaveBeenCalled();

        rerender(<DataTable columns={columns} rows={[]} empty={<p>Nothing here</p>} />);
        expect(screen.getByText('Nothing here')).toBeInTheDocument();
    });

    it('also renders the mobile card list', () => {
        const { container } = render(<DataTable columns={columns} rows={rows} />);
        expect(container.querySelectorAll('ul > li')).toHaveLength(2);
    });
});
