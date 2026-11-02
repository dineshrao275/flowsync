import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import OffboardingCases from './OffboardingCases';

const get = vi.fn();

vi.mock('../../services/api', () => ({ default: { get: (...a) => get(...a), post: vi.fn() }, fieldErrors: () => ({}) }));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => true }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

describe('OffboardingCases status filter', () => {
    beforeEach(() => {
        get.mockReset();
        get.mockImplementation(() => new Promise(() => {}));
    });

    // The onboarding block owned stInProgress/stCompleted/stCancelled, so this page showed the raw key
    // "hrms.offboarding.stInProgress" in its Status filter.
    it('labels every status option with real text, never a translation key', () => {
        render(<MemoryRouter><OffboardingCases /></MemoryRouter>);

        const labels = ['Initiated', 'In progress', 'Completed', 'Cancelled'];
        labels.forEach((label) => expect(screen.getByRole('option', { name: label })).toBeInTheDocument());
        expect(screen.queryByRole('option', { name: /hrms\.offboarding\./ })).not.toBeInTheDocument();
    });
});
