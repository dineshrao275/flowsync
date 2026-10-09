import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import IntakeWizard from './IntakeWizard';

function Harness({ onSaveStep }) {
    const [values, setValues] = useState({ name: '', slug: '', industry: '', company_size: '', country: '' });
    return <IntakeWizard mode="register" values={values} onChange={setValues} plans={[]} onSaveStep={onSaveStep} onSubmit={() => {}} />;
}

describe('IntakeWizard', () => {
    it('blocks Next and never saves a step while required fields are empty', async () => {
        const save = vi.fn().mockResolvedValue(true);
        render(<Harness onSaveStep={save} />);

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));

        expect((await screen.findAllByText('This field is required.')).length).toBeGreaterThan(0);
        expect(save).not.toHaveBeenCalled();
    });

    it('derives the slug from the company name until it is edited by hand', async () => {
        render(<Harness onSaveStep={vi.fn()} />);

        fireEvent.change(screen.getByLabelText(/Company name/), { target: { value: 'Acme Inc!' } });

        await waitFor(() => expect(screen.getByLabelText(/Workspace URL/).value).toBe('acme-inc'));
    });
});
