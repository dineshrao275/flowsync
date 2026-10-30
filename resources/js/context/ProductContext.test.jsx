import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ProductProvider, useActiveProduct } from './ProductContext';
import { productStorageKey } from '../utils/product';

let auth;
vi.mock('./AuthContext', () => ({ useAuth: () => auth }));

const both = { id: 7, tenant: { id: 3 }, products: { tms: true, hrms: true } };
const hrmsOnly = { ...both, products: { tms: false, hrms: true } };

function Probe() {
    const { activeProduct } = useActiveProduct();
    const location = useLocation();

    return (
        <p data-testid="probe">
            {activeProduct}|{location.pathname}|{location.state?.product ?? ''}
        </p>
    );
}

function SwitchButton({ product }) {
    const { switchProduct } = useActiveProduct();

    return (
        <button type="button" onClick={() => switchProduct(product)}>
            switch {product}
        </button>
    );
}

function renderAt(entry, { user = both, children = null } = {}) {
    auth = { user, loading: false };

    return render(
        <MemoryRouter initialEntries={[entry]}>
            <ProductProvider>
                {children}
                <Probe />
            </ProductProvider>
        </MemoryRouter>,
    );
}

describe('ProductProvider', () => {
    afterEach(() => {
        window.localStorage.clear();
    });

    it('restores the stored product on a shared route', async () => {
        window.localStorage.setItem(productStorageKey(both), 'hrms');

        renderAt('/subscription');

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('hrms|/subscription|'));
    });

    it('lets an owned route win over the stored product', async () => {
        window.localStorage.setItem(productStorageKey(both), 'hrms');

        renderAt('/dashboard');

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('tms|/dashboard|'));
        await waitFor(() => expect(window.localStorage.getItem(productStorageKey(both))).toBe('tms'));
    });

    it('clamps a stored product the tenant no longer has', async () => {
        window.localStorage.setItem(productStorageKey(hrmsOnly), 'tms');

        renderAt('/settings', { user: hrmsOnly });

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('hrms|/settings|'));
        await waitFor(() => expect(window.localStorage.getItem(productStorageKey(hrmsOnly))).toBe('hrms'));
    });

    it('uses navigation state on a shared route', async () => {
        window.localStorage.setItem(productStorageKey(both), 'tms');

        renderAt({ pathname: '/users', state: { product: 'hrms' } });

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('hrms|/users|hrms'));
    });

    it('persists an owned product for the next shared page', async () => {
        renderAt('/hrms');

        await waitFor(() => expect(window.localStorage.getItem(productStorageKey(both))).toBe('hrms'));
    });

    it('switches to an available product', async () => {
        window.localStorage.setItem(productStorageKey(both), 'tms');

        renderAt('/subscription', { children: <SwitchButton product="hrms" /> });

        fireEvent.click(screen.getByRole('button', { name: 'switch hrms' }));

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('hrms|/subscription|'));
        expect(window.localStorage.getItem(productStorageKey(both))).toBe('hrms');
    });

    it('refuses a product the tenant does not have', async () => {
        window.localStorage.setItem(productStorageKey(hrmsOnly), 'hrms');

        renderAt('/subscription', { user: hrmsOnly, children: <SwitchButton product="tms" /> });

        fireEvent.click(screen.getByRole('button', { name: 'switch tms' }));

        await waitFor(() => expect(screen.getByTestId('probe')).toHaveTextContent('hrms|/subscription|'));
        expect(window.localStorage.getItem(productStorageKey(hrmsOnly))).toBe('hrms');
    });
});
