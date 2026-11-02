import { describe, expect, it } from 'vitest';
import { grantsAny } from './permissions';

describe('grantsAny', () => {
    it('matches an exact slug', () => {
        expect(grantsAny(['hrms.expenses.view'], 'hrms.expenses.view')).toBe(true);
        expect(grantsAny(['hrms.expenses.view_own'], 'hrms.expenses.view')).toBe(false);
    });

    it('accepts any one of several slugs, so an assigned-scope manager passes a queue gate', () => {
        const gate = 'hrms.expenses.view|hrms.expenses.view_assigned|hrms.expenses.view_all';

        expect(grantsAny(['hrms.expenses.view_assigned'], gate)).toBe(true);
        expect(grantsAny(['hrms.expenses.view_all'], gate)).toBe(true);
        // Own-only holders keep to "My expenses".
        expect(grantsAny(['hrms.expenses.view_own'], gate)).toBe(false);
        expect(grantsAny(undefined, gate)).toBe(false);
    });
});
