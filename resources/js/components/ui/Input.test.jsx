import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Input from './Input';

describe('Input label association', () => {
    it('links a label to its input even when no id or name is given', () => {
        render(<Input label="Pay head name" />);
        expect(screen.getByLabelText('Pay head name')).toBeInTheDocument();
    });

    it('links a date field label too', () => {
        render(<Input label="Effective from" type="date" />);
        expect(screen.getByLabelText('Effective from')).toBeInTheDocument();
    });

    it('gives two unnamed inputs distinct ids', () => {
        render(<><Input label="First" /><Input label="Second" /></>);
        expect(screen.getByLabelText('First').id).not.toBe(screen.getByLabelText('Second').id);
    });

    it('keeps an explicit id', () => {
        render(<Input label="Code" id="code-field" />);
        expect(screen.getByLabelText('Code').id).toBe('code-field');
    });

    it('applies pr-9 padding and renders clear button when date value is present', () => {
        render(<Input label="Start Date" type="date" value="2025-11-23" onChange={() => {}} />);
        const input = screen.getByLabelText('Start Date');
        expect(input).toBeInTheDocument();
        expect(input).toHaveClass('pr-9');
        const clearBtn = document.querySelector('.react-datepicker__close-icon');
        expect(clearBtn).toBeInTheDocument();
    });
});
