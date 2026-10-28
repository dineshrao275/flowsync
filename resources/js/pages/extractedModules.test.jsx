import { describe, expect, it } from 'vitest';

// Pages split into sibling modules: loading each proves every moved declaration is exported
// and every import path resolves (the build and ESLint already pass; this guards the runtime).
const modules = {
    Tenants: () => import('./Tenants'),
    TenantActions: () => import('../components/tenant/TenantActions'),
    Analytics: () => import('./hrms/Analytics'),
    AnalyticsTabs: () => import('../components/hrms/analytics/AnalyticsTabs'),
    Org: () => import('./hrms/Org'),
    OrgPanels: () => import('../components/hrms/OrgPanels'),
    Dashboard: () => import('./Dashboard'),
    DashboardCharts: () => import('../components/dashboard/DashboardCharts'),
    WorkspaceProjectsTab: () => import('../components/workspace/WorkspaceProjectsTab'),
    WorkspaceMembersTab: () => import('../components/workspace/WorkspaceMembersTab'),
    WorkspaceLabelsTab: () => import('../components/workspace/WorkspaceLabelsTab'),
    WorkspaceSettingsTab: () => import('../components/workspace/WorkspaceSettingsTab'),
};

describe('split page modules', () => {
    it.each(Object.keys(modules))('%s loads and exposes its components', async (name) => {
        const mod = await modules[name]();
        const exported = Object.values(mod);

        expect(exported.length).toBeGreaterThan(0);
        expect(exported.every((value) => typeof value === 'function')).toBe(true);
    });

    it('the extracted modules export what their pages import', async () => {
        expect(Object.keys(await import('../components/hrms/analytics/AnalyticsTabs'))).toEqual(
            expect.arrayContaining(['OverviewTab', 'AttendanceTab', 'LeaveTab', 'LifecycleTab', 'PerformanceTab', 'PayrollTab']),
        );
        expect(Object.keys(await import('../components/hrms/OrgPanels'))).toEqual(
            expect.arrayContaining(['DepartmentPanel', 'CatalogPanel', 'CatalogList']),
        );
        expect(Object.keys(await import('../components/tenant/TenantActions'))).toEqual(expect.arrayContaining(['TenantActions']));
        expect(Object.keys(await import('../components/workspace/WorkspaceProjectsTab'))).toEqual(expect.arrayContaining(['WorkspaceProjectsTab']));
        expect(Object.keys(await import('../components/workspace/WorkspaceMembersTab'))).toEqual(expect.arrayContaining(['WorkspaceMembersTab']));
        expect(Object.keys(await import('../components/workspace/WorkspaceLabelsTab'))).toEqual(expect.arrayContaining(['WorkspaceLabelsTab']));
        expect(Object.keys(await import('../components/workspace/WorkspaceSettingsTab'))).toEqual(expect.arrayContaining(['WorkspaceSettingsTab']));
    });
});
