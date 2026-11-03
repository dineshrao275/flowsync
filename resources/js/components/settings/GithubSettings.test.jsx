import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GithubSettings from './GithubSettings';

const { get, perms } = vi.hoisted(() => ({ get: vi.fn(), perms: { git: true } }));
vi.mock('../../services/api', () => ({ default: { get: (...a) => get(...a), put: vi.fn() } }));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ hasModule: (m) => m === 'git' && perms.git }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn() }) }));

describe('GithubSettings', () => {
    beforeEach(() => {
        get.mockReset();
        get.mockResolvedValue({ data: { enabled: false } });
    });

    it('points tenants whose plan has the GitHub App at the integration page', async () => {
        perms.git = true;
        render(<MemoryRouter><GithubSettings /></MemoryRouter>);

        const link = await screen.findByRole('link', { name: /connect the github app/i });
        expect(link).toHaveAttribute('href', '/integrations');
    });

    it('shows no notice when the plan does not include the module', async () => {
        perms.git = false;
        render(<MemoryRouter><GithubSettings /></MemoryRouter>);

        await screen.findByText(/status/i);
        expect(screen.queryByRole('link', { name: /connect the github app/i })).not.toBeInTheDocument();
    });
});
