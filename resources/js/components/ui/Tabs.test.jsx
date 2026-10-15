import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Tabs from './Tabs';

const tabs = [
    { key: 'projects', label: 'Projects' },
    { key: 'members', label: 'Members (3)' },
    { key: 'settings', label: 'Settings' },
];

describe('Tabs', () => {
    it('renders every tab label, counts included', () => {
        render(<Tabs tabs={tabs} active="projects" onChange={() => {}} />);

        expect(screen.getByText('Projects')).toBeInTheDocument();
        expect(screen.getByText('Members (3)')).toBeInTheDocument();
        expect(screen.getByText('Settings')).toBeInTheDocument();
    });

    it('calls onChange with the clicked tab key', () => {
        const onChange = vi.fn();
        render(<Tabs tabs={tabs} active="projects" onChange={onChange} />);

        fireEvent.click(screen.getByText('Members (3)'));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledWith('members');
    });

    it('accent-marks the active tab only', () => {
        render(<Tabs tabs={tabs} active="members" onChange={() => {}} />);

        expect(screen.getByText('Members (3)').className).toContain('border-[var(--accent)]');
        expect(screen.getByText('Projects').className).toContain('border-transparent');
    });
});
