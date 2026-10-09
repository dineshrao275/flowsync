import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import ProtectedRoute from './ProtectedRoute';

let auth;
vi.mock('../context/AuthContext', () => ({ useAuth: () => auth }));

function renderAt(path, routes) {
    return render(
        <MemoryRouter initialEntries={[path]}>
            <Routes>
                {routes}
                <Route path="/login" element={<p>login page</p>} />
                <Route path="/403" element={<p>forbidden page</p>} />
                <Route path="/module-denied" element={<p>module denied page</p>} />
            </Routes>
        </MemoryRouter>,
    );
}

const signedIn = (overrides = {}) => ({
    user: { id: 1 },
    loading: false,
    can: () => true,
    hasModule: () => true,
    ...overrides,
});

describe('ProtectedRoute', () => {
    it('renders the page when wrapped around a leaf route (P0.1 regression: it used to render nothing)', () => {
        auth = signedIn();

        renderAt('/page', <Route path="/page" element={<ProtectedRoute permission="x.view"><p>the page</p></ProtectedRoute>} />);

        expect(screen.getByText('the page')).toBeInTheDocument();
    });

    it('renders nested routes when used as a layout route', () => {
        auth = signedIn();

        renderAt(
            '/page',
            <Route element={<ProtectedRoute />}>
                <Route path="/page" element={<p>nested page</p>} />
            </Route>,
        );

        expect(screen.getByText('nested page')).toBeInTheDocument();
    });

    it('sends a signed-out visitor to login', () => {
        auth = signedIn({ user: null });

        renderAt('/page', <Route path="/page" element={<ProtectedRoute><p>the page</p></ProtectedRoute>} />);

        expect(screen.getByText('login page')).toBeInTheDocument();
        expect(screen.queryByText('the page')).not.toBeInTheDocument();
    });

    it('sends a user without the permission to /403 and never renders the page', () => {
        auth = signedIn({ can: () => false });

        renderAt('/page', <Route path="/page" element={<ProtectedRoute permission="x.view"><p>the page</p></ProtectedRoute>} />);

        expect(screen.getByText('forbidden page')).toBeInTheDocument();
        expect(screen.queryByText('the page')).not.toBeInTheDocument();
    });

    it('sends a user whose plan lacks the module to the module-denied page', () => {
        auth = signedIn({ hasModule: () => false });

        renderAt('/page', <Route path="/page" element={<ProtectedRoute module="reports"><p>the page</p></ProtectedRoute>} />);

        expect(screen.getByText('module denied page')).toBeInTheDocument();
    });
});
