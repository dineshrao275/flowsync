import { describe, expect, it } from 'vitest';
import { projectRoleLabel, workspaceRoleLabel } from './roles';

describe('role labels', () => {
    it('lowercases known project-role slugs', () => {
        expect(projectRoleLabel('lead')).toBe('lead');
        expect(projectRoleLabel('developer')).toBe('developer');
        expect(projectRoleLabel('viewer')).toBe('viewer');
    });

    it('falls back to the role name for a custom slug', () => {
        expect(projectRoleLabel('custom', 'Custom Role')).toBe('custom role');
        expect(projectRoleLabel('custom')).toBe('custom');
    });

    it('lowercases known workspace-role slugs', () => {
        expect(workspaceRoleLabel('owner')).toBe('owner');
        expect(workspaceRoleLabel('admin')).toBe('admin');
        expect(workspaceRoleLabel('member')).toBe('member');
    });

    it('returns an empty string for a missing slug', () => {
        expect(projectRoleLabel(null)).toBe('');
        expect(workspaceRoleLabel(undefined)).toBe('');
    });
});
