import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PasswordInput from './PasswordInput';

describe('PasswordInput', () => {
    it('toggles the field between hidden and visible', () => {
        render(<PasswordInput label="Password" name="password" value="secret" onChange={() => {}} />);

        const input = screen.getByLabelText('Password');
        expect(input).toHaveAttribute('type', 'password');

        const toggle = screen.getByRole('button', { name: 'Show password' });
        expect(toggle).toHaveAttribute('aria-pressed', 'false');
        expect(toggle).toHaveAttribute('aria-controls', 'password');

        fireEvent.click(toggle);
        expect(input).toHaveAttribute('type', 'text');
        expect(screen.getByRole('button', { name: 'Hide password' })).toHaveAttribute('aria-pressed', 'true');

        fireEvent.click(screen.getByRole('button', { name: 'Hide password' }));
        expect(input).toHaveAttribute('type', 'password');
    });

    it('keeps the normal Input contract (name, error, disabled)', () => {
        render(
            <PasswordInput
                label="Confirm password"
                name="password_confirmation"
                value=""
                onChange={() => {}}
                error="Required"
                disabled
            />,
        );

        const input = screen.getByLabelText('Confirm password');
        expect(input).toHaveAttribute('name', 'password_confirmation');
        expect(input).toBeDisabled();
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('Required')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Show password' })).toBeDisabled();
    });
});
