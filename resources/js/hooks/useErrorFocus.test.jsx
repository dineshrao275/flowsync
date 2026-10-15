import { useRef, useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import useErrorFocus from './useErrorFocus';

function Harness({ errors }) {
    const ref = useRef(null);
    useErrorFocus(errors, ref);

    return (
        <form ref={ref}>
            <input aria-label="Email" aria-invalid={errors.email ? true : undefined} />
            {errors.form && <div role="alert" tabIndex={-1}>{errors.form}</div>}
        </form>
    );
}

function TriggeringHarness() {
    const [errors, setErrors] = useState({});
    const ref = useRef(null);
    useErrorFocus(errors, ref);

    return (
        <form ref={ref}>
            <input aria-label="Email" aria-invalid={errors.email ? true : undefined} />
            {errors.form && <div role="alert" tabIndex={-1}>{errors.form}</div>}
            <button type="button" onClick={() => setErrors({ email: 'Required' })}>fail</button>
        </form>
    );
}

describe('useErrorFocus', () => {
    it('focuses the first invalid field when errors appear', () => {
        render(<TriggeringHarness />);

        fireEvent.click(screen.getByRole('button', { name: 'fail' }));

        expect(screen.getByLabelText('Email')).toHaveFocus();
    });

    it('falls back to the alert for form-level errors', () => {
        render(<Harness errors={{ form: 'Something went wrong' }} />);

        expect(screen.getByRole('alert')).toHaveFocus();
    });
});
