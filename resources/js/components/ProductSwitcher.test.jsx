import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ProductProvider, useActiveProduct } from '../context/ProductContext';
import { productStorageKey } from '../utils/product';
import ProductSwitcher from './ProductSwitcher';

let auth;
vi.mock('../context/AuthContext', () => ({ useAuth: () => auth }));

const both = { id: 7, tenant: { id: 3 }, products: { tms: true, hrms: true } };
const hrmsOnly = { ...both, products: { tms: false, hrms: true } };
const tmsOnly = { ...both, products: { tms: true, hrms: false } };

function session(user, { dashboard = true, workspaces = true } = {}) {
    return {
        user,
        loading: false,
        check: (capability) => {
            if (capability === 'product:tms') return user.products.tms !== false;
            if (capability === 'product:hrms') return user.products.hrms !== false;

            return true;
        },
        can: (permission) => {
            if (permission === 'dashboard.view') return dashboard;
            if (permission === 'workspaces.view') return workspaces;

            return true;
        },
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
                <ProductSwitcher />
                <Probe />
            </ProductProvider>
        </MemoryRouter>,
    );
}

describe('ProductSwitcher', () => {
    afterEach(() => {
        window.localStorage.clear();
    });

    it('switches from HRMS to TMS', async () => {
        renderAt('/hrms');

        fireEvent.click(screen.getByRole('button', { name: 'Switch to Task Management' }));

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('tms|/dashboard'));
        expect(window.localStorage.getItem(productStorageKey(both))).toBe('tms');
    });

    it('switches from TMS to HRMS', async () => {
        renderAt('/dashboard');

        fireEvent.click(screen.getByRole('button', { name: 'Switch to HR Management' }));

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('hrms|/hrms'));
        expect(window.localStorage.getItem(productStorageKey(both))).toBe('hrms');
    });

    it('uses the shared page context rather than assuming TMS', async () => {
        window.localStorage.setItem(productStorageKey(both), 'hrms');

        renderAt('/subscription');

        fireEvent.click(screen.getByRole('button', { name: 'Switch to Task Management' }));

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('tms|/dashboard'));
    });

    it('falls back to workspaces when the caller cannot open the dashboard', async () => {
        renderAt('/hrms', both, { dashboard: false });

        fireEvent.click(screen.getByRole('button', { name: 'Switch to Task Management' }));

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('tms|/workspaces'));
    });

    it('is hidden from an HRMS-only tenant', () => {
        renderAt('/hrms', hrmsOnly);

        expect(screen.queryByRole('button', { name: /Switch to/ })).not.toBeInTheDocument();
    });

    it('is hidden from a TMS-only tenant', () => {
        renderAt('/dashboard', tmsOnly);

        expect(screen.queryByRole('button', { name: /Switch to/ })).not.toBeInTheDocument();
    });
});
