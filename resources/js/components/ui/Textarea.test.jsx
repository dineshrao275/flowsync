import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import FormField from './FormField';
import Textarea from './Textarea';

describe('Textarea', () => {
    it('renders a visible, associated label and error when given label/error props', () => {
        render(<Textarea label="Reason" error="The reason field is required." />);

        const field = screen.getByLabelText('Reason');
        expect(field.tagName).toBe('TEXTAREA');
        expect(field).not.toHaveAttribute('label');
        expect(field).not.toHaveAttribute('error');
        expect(screen.getByText('The reason field is required.')).toBeInTheDocument();
        expect(field).toHaveAttribute('aria-invalid', 'true');
    });

    it('is still a bare control inside FormField (no duplicate label)', () => {
        render(<FormField label="Notes"><Textarea /></FormField>);

        expect(screen.getAllByText('Notes')).toHaveLength(1);
        expect(screen.getByLabelText('Notes').tagName).toBe('TEXTAREA');
    });
});
