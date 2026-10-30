import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PlanFeaturesExplorer from './PlanFeaturesExplorer';

// Wrap with mock translations if i18n not initialized in isolated test
vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key, opts) => {
            if (opts?.count !== undefined) {
                if (key === 'admin.subscription.unlockedCount') return `${opts.count} included`;
                if (key === 'admin.subscription.lockedCount') return `${opts.count} locked`;
            }
            if (opts?.defaultValue) return opts.defaultValue;
            return key;
        },
    }),
}));

describe('PlanFeaturesExplorer', () => {
    const included = ['time_tracking'];
    const available = ['time_tracking', 'sprints', 'hrms.core', 'reports'];

    it('renders feature counts correctly and starts collapsed by default', () => {
        render(<PlanFeaturesExplorer includedModules={included} availableModules={available} />);

        expect(screen.getByText('1 included')).toBeInTheDocument();
        // Body is collapsed, so search input is not visible initially
        expect(screen.queryByPlaceholderText('admin.subscription.searchFeaturesPlaceholder')).not.toBeInTheDocument();
        expect(screen.getByText('Expand')).toBeInTheDocument();
    });

    it('toggles expand and collapse when header is clicked', () => {
        render(<PlanFeaturesExplorer includedModules={included} availableModules={available} />);

        const headerButton = screen.getByRole('button', { expanded: false });
        // Expand
        fireEvent.click(headerButton);
        expect(screen.getByPlaceholderText('admin.subscription.searchFeaturesPlaceholder')).toBeInTheDocument();
        expect(screen.getByText('Collapse')).toBeInTheDocument();

        // Collapse
        fireEvent.click(headerButton);
        expect(screen.queryByPlaceholderText('admin.subscription.searchFeaturesPlaceholder')).not.toBeInTheDocument();
        expect(screen.getByText('Expand')).toBeInTheDocument();
    });

    it('displays included status for unlocked modules when expanded', () => {
        render(<PlanFeaturesExplorer includedModules={included} availableModules={available} defaultExpanded={true} />);

        expect(screen.getByText('Time Tracking')).toBeInTheDocument();
    });

    it('filters features by search input', () => {
        render(<PlanFeaturesExplorer includedModules={included} availableModules={available} defaultExpanded={true} />);

        const searchInput = screen.getByPlaceholderText('admin.subscription.searchFeaturesPlaceholder');
        fireEvent.change(searchInput, { target: { value: 'Sprints' } });

        expect(screen.getByText('Sprints & Agile')).toBeInTheDocument();
        expect(screen.queryByText('Employee Directory')).not.toBeInTheDocument();
    });

    it('filters by status tabs', () => {
        render(<PlanFeaturesExplorer includedModules={included} availableModules={available} defaultExpanded={true} />);

        const includedTab = screen.getByRole('button', { name: /admin.subscription.filterIncluded/i });
        fireEvent.click(includedTab);

        expect(screen.getByText('Time Tracking')).toBeInTheDocument();
        expect(screen.queryByText('Sprints & Agile')).not.toBeInTheDocument();
    });

    it('calls onComparePlans when upgrade link is clicked', () => {
        const onComparePlans = vi.fn();
        render(
            <PlanFeaturesExplorer
                includedModules={included}
                availableModules={available}
                onComparePlans={onComparePlans}
                defaultExpanded={true}
            />,
        );

        const upgradeButtons = screen.getAllByRole('button', { name: /admin.subscription.viewUpgradePlans/i });
        expect(upgradeButtons.length).toBeGreaterThan(0);
        fireEvent.click(upgradeButtons[0]);

        expect(onComparePlans).toHaveBeenCalledTimes(1);
    });
});
