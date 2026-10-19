import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Alert from './Alert';
import Button from './Button';
import Card from './Card';
import EmptyState from './EmptyState';
import Skeleton from './Skeleton';
import { Table, Td, Th } from './Table';

describe('Button', () => {
    it('marks a loading button busy and disabled with a hidden spinner', () => {
        render(<Button loading>Save</Button>);
        const button = screen.getByRole('button', { name: 'Save' });

        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
    });

    it('leaves a settled button enabled without the busy flag', () => {
        render(<Button>Save</Button>);
        const button = screen.getByRole('button', { name: 'Save' });

        expect(button).toBeEnabled();
        expect(button).not.toHaveAttribute('aria-busy');
    });

    it('tints keyboard focus per variant instead of a shared ring', () => {
        render(<Button variant="danger">Delete</Button>);
        const button = screen.getByRole('button', { name: 'Delete' });

        expect(button.className).toContain('focus-visible:outline-2');
        expect(button.className).toContain('focus-visible:outline-[var(--danger)]');
        expect(button.className).not.toContain('focus:ring');
    });
});

describe('Card', () => {
    it('renders flat without a hover lift by default', () => {
        const { container } = render(<Card title="Quiet">body</Card>);
        const panel = container.firstChild;

        expect(panel.className).toContain('shadow-card');
        expect(panel.className).not.toContain('hover:shadow-popover');
    });

    it('opts into the hover lift only for interactive cards', () => {
        const { container } = render(<Card elevation="interactive">body</Card>);
        const panel = container.firstChild;

        expect(panel.className).toContain('hover:shadow-popover');
        expect(panel.className).toContain('cursor-pointer');
    });
});

describe('Skeleton', () => {
    it('announces itself as loading without stealing focus', () => {
        render(<Skeleton variant="block" />);
        const placeholder = screen.getByRole('status', { name: 'Loading' });

        expect(placeholder).toHaveAttribute('aria-label', 'Loading');
    });
});

describe('EmptyState', () => {
    it('renders the action slot below the helper copy', () => {
        render(<EmptyState title="Nothing here" description="Get started" action={<button type="button">Create</button>} />);

        expect(screen.getByText('Nothing here')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Create' })).toBeInTheDocument();
    });
});

describe('Alert', () => {
    it('announces errors to assistive tech', () => {
        render(<Alert>Unable to load.</Alert>);

        expect(screen.getByRole('alert')).toHaveTextContent('Unable to load.');
    });
});

describe('Table', () => {
    function Grid(props) {
        return (
            <Table {...props}>
                <thead>
                    <tr>
                        <Th>Name</Th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <Td>Ava</Td>
                    </tr>
                </tbody>
            </Table>
        );
    }

    it('pins the header row by default', () => {
        const { container } = render(<Grid />);
        const th = container.querySelector('th');

        expect(th.className).toContain('sticky');
        expect(th.getAttribute('scope')).toBe('col');
    });

    it('tightens cell padding for compact tables', () => {
        const { container } = render(<Grid compact />);
        const th = container.querySelector('th');
        const td = container.querySelector('td');

        expect(th.className).toContain('px-3 py-1.5');
        expect(td.className).toContain('px-3 py-1.5');
    });
});
