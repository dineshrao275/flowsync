import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import GitNextSteps from './GitNextSteps';

const show = (canManage) => render(<MemoryRouter><GitNextSteps canManage={canManage} /></MemoryRouter>);

describe('GitNextSteps', () => {
    it('tells an admin the path from connection to a visible branch, including the optional identity step', () => {
        show(true);

        expect(screen.getByText(/link a repository to a project/i)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /go to projects/i })).toHaveAttribute('href', '/projects');
        expect(screen.getByText(/work from your tasks/i)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /map logins below/i })).toHaveAttribute('href', '#git-identities');
    });

    it('omits the identity step for people who cannot manage Git', () => {
        show(false);

        expect(screen.queryByText(/map github logins/i)).not.toBeInTheDocument();
    });
});
