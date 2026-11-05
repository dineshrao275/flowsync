import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PlatformIntegrations from './PlatformIntegrations';

const { get, post, confirm, perms } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), confirm: vi.fn(), perms: { manage: true } }));

vi.mock('../services/api', () => ({ default: { get: (...a) => get(...a), post: (...a) => post(...a) }, fieldErrors: () => ({}) }));
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ can: () => perms.manage }) }));
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../context/BreadcrumbContext', () => ({ useSetCrumbs: () => vi.fn() }));
vi.mock('../components/ui/ConfirmDialog', () => ({ useConfirm: () => confirm }));

const connection = {
    uuid: 'c-1', tenant: { id: 1, name: 'Acme', slug: 'acme' }, account_login: 'acme-org', status: 'active', disabled_by_platform: false,
    repositories_count: 4, stale: true, last_delivery_at: null, failed_deliveries_24h: 2,
};
const health = { connections: { active: 1 }, failed_deliveries_24h: 2, stale_connections: 1, last_delivery_at: null };

describe('PlatformIntegrations', () => {
    beforeEach(() => {
        get.mockReset(); post.mockReset(); confirm.mockReset(); perms.manage = true;
        get.mockImplementation((url) => Promise.resolve({ data: url.includes('deliveries')
            ? { deliveries: [{ id: 1, event: 'push', action: null, status: 'failed', error: 'boom', tenant_id: 1, received_at: null }] }
            : { connections: [connection], health } }));
    });

    it('lists connections with health and flags stale ones', async () => {
        render(<PlatformIntegrations />);

        expect((await screen.findAllByText('acme-org')).length).toBeGreaterThan(0);
        expect(screen.getAllByText('stale').length).toBeGreaterThan(0);
        expect(screen.getByText('Failed (24h)', { selector: 'p' })).toBeInTheDocument();
    });

    it('disables a connection only after confirmation', async () => {
        post.mockResolvedValue({ data: {} });
        confirm.mockResolvedValueOnce(false).mockResolvedValueOnce(true);
        render(<PlatformIntegrations />);

        const button = (await screen.findAllByRole('button', { name: 'Disable' }))[0];
        fireEvent.click(button);
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(button);
        await waitFor(() => expect(post).toHaveBeenCalledWith('/system/git/connections/c-1/disable'));
    });

    it('hides the actions from staff who may only view', async () => {
        perms.manage = false;
        render(<PlatformIntegrations />);

        await screen.findAllByText('acme-org');
        expect(screen.queryByRole('button', { name: 'Disable' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sync' })).not.toBeInTheDocument();
    });

    it('shows the delivery log without any payload text', async () => {
        render(<PlatformIntegrations />);

        fireEvent.click(await screen.findByRole('tab', { name: 'Deliveries' }));
        expect((await screen.findAllByText('boom')).length).toBeGreaterThan(0);
    });
});
