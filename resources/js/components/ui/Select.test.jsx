import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Select from './Select';

describe('Select label association', () => {
    it('labels the control even when the caller passes neither id nor name', () => {
        render(<Select label="Category"><option value="">Pick</option></Select>);

        expect(screen.getByLabelText('Category')).toBeInTheDocument();
    });

    it('keeps an explicit id', () => {
        render(<Select label="Status" id="status-x"><option value="a">A</option></Select>);

        expect(screen.getByLabelText('Status')).toHaveAttribute('id', 'status-x');
    });

    it('gives two unnamed selects distinct ids', () => {
        render(<><Select label="One"><option /></Select><Select label="Two"><option /></Select></>);

        expect(screen.getByLabelText('One').id).not.toBe(screen.getByLabelText('Two').id);
    });
});
