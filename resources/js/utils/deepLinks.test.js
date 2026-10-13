import { describe, expect, it } from 'vitest';
import { taskUrl, projectUrl, workspaceUrl } from './deepLinks';
import { notificationHref } from './notifications';

describe('deepLinks and notificationHref', () => {
    it('builds basic taskUrl without section', () => {
        expect(taskUrl(1, 'TP-1')).toBe('/projects/1?tab=tasks&task=TP-1');
    });

    it('builds taskUrl with section', () => {
        expect(taskUrl(1, 'TP-1', 'comments')).toBe('/projects/1?tab=tasks&task=TP-1&section=comments');
    });

    it('builds taskUrl with section and comment parameter', () => {
        expect(taskUrl(1, 'TP-1', 'comments', 42)).toBe('/projects/1?tab=tasks&task=TP-1&section=comments&comment=42');
        expect(taskUrl(1, 'TP-1', 'comments', { comment: 42 })).toBe('/projects/1?tab=tasks&task=TP-1&section=comments&comment=42');
    });

    it('generates exact comment deep link for task.commented notifications', () => {
        const href = notificationHref(
            { project_id: 5, key: 'APP-10', comment_id: 123 },
            'task.commented'
        );
        expect(href).toBe('/projects/5?tab=tasks&task=APP-10&section=comments&comment=123');
    });

    it('generates exact time deep link for task.work_logged notifications', () => {
        const href = notificationHref(
            { project_id: 5, key: 'APP-10' },
            'task.work_logged'
        );
        expect(href).toBe('/projects/5?tab=tasks&task=APP-10&section=time');
    });

    it('generates exact dependencies deep link for task.unblocked notifications', () => {
        const href = notificationHref(
            { project_id: 5, key: 'APP-10' },
            'task.unblocked'
        );
        expect(href).toBe('/projects/5?tab=tasks&task=APP-10&section=dependencies');
    });

    it('builds projectUrl and workspaceUrl correctly', () => {
        expect(projectUrl(2)).toBe('/projects/2');
        expect(projectUrl(2, 'tasks')).toBe('/projects/2?tab=tasks');
        expect(workspaceUrl(3)).toBe('/workspaces/3');
        expect(workspaceUrl(3, 'members')).toBe('/workspaces/3?tab=members');
    });
});
