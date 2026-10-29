import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ProductProvider, useActiveProduct } from '../context/ProductContext';
import { productStorageKey } from '../utils/product';
import Sidebar from './Sidebar';

let auth;
vi.mock('../context/AuthContext', () => ({ useAuth: () => auth }));
vi.mock('../context/NotificationContext', () => ({
    useNotifications: () => ({
        inboxUnread: 0,
        unreadCount: 0,
        effectivePlatformCounts: {},
        effectiveInboxUnread: 0,
        markSectionViewed: vi.fn(),
    }),
}));

const both = { id: 7, tenant: { id: 3 }, products: { tms: true, hrms: true } };
const hrmsOnly = { ...both, products: { tms: false, hrms: true } };

function session(user, { denied = [] } = {}) {
    return {
        user,
        loading: false,
        check: (capability) => !denied.includes(capability.replace('permission:', '')),
        can: (permission) => !denied.includes(permission),
        hasModule: () => true,
    };
}

function Probe() {
    const { activeProduct } = useActiveProduct();
    const location = useLocation();

    return (
        <p data-testid="probe">
            {activeProduct}|{location.pathname}
        </p>
    );
}

function renderAt(entry, user = both, options = {}) {
    auth = session(user, options);

    return render(
        <MemoryRouter initialEntries={[entry]}>
            <ProductProvider>
                <Sidebar open collapsed={false} onClose={() => {}} onToggleCollapse={() => {}} />
                <Probe />
            </ProductProvider>
        </MemoryRouter>,
    );
}

describe('Sidebar product context', () => {
    afterEach(() => {
        window.localStorage.clear();
    });

    it('keeps the HRMS shell when opening shared Administration', async () => {
        renderAt('/hrms');

        expect(screen.getByRole('link', { name: 'My HR' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Workspaces' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('link', { name: 'Users' }));

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('hrms|/users'));
        expect(screen.getByRole('button', { name: 'Overview' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Workspaces' })).not.toBeInTheDocument();
    });

    it('keeps the TMS shell when opening shared Administration', async () => {
        renderAt('/dashboard');

        expect(screen.getByRole('link', { name: 'Workspaces' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'My HR' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('link', { name: 'Users' }));

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('tms|/users'));
        expect(screen.getByRole('link', { name: 'Workspaces' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'My HR' })).not.toBeInTheDocument();
    });

    it('starts an HRMS-only tenant in the HR shell on a shared page', async () => {
        window.localStorage.setItem(productStorageKey(hrmsOnly), 'hrms');

        renderAt('/subscription', hrmsOnly);

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('hrms|/subscription'));
        expect(screen.getByRole('button', { name: 'Overview' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Workspaces' })).not.toBeInTheDocument();
    });

    it('removes navigation the caller is not permitted to use', () => {
        renderAt('/hrms', both, { denied: ['users.view'] });

        expect(screen.queryByRole('link', { name: 'Users' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Roles' })).toBeInTheDocument();
    });
});
