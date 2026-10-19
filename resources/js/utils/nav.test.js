import { describe, expect, it } from 'vitest';
import { hrmsSections, superAdminSections, tmsSections } from './nav';

// The shell renders every `icon` as a Phosphor component — a bad import or
// a leftover path string would silently render nothing, so pin the shape.
describe('sidebar nav icons', () => {
    const cases = [
        ['tms', tmsSections],
        ['hrms', hrmsSections],
        ['platform', superAdminSections],
    ];

    for (const [shell, sections] of cases) {
        it(`every ${shell} item carries a renderable icon component`, () => {
            expect(sections.length).toBeGreaterThan(0);

            for (const section of sections) {
                for (const item of section.items) {
                    expect(
                        item.icon !== null && (typeof item.icon === 'function' || typeof item.icon === 'object'),
                        `${shell}/${section.label}/${item.label} has no icon component`,
                    ).toBe(true);
                }
            }
        });
    }
});
