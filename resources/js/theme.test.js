import { describe, expect, it } from 'vitest';
import { DEFAULT_THEME, contrastRatio, themeContrastIssues } from './theme';

describe('theme contrast', () => {
    it('computes WCAG ratios', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 0);
        expect(contrastRatio('#ffffff', '#ffffff')).toBeCloseTo(1, 5);
    });

    it('accepts the shipped default theme', () => {
        expect(themeContrastIssues(DEFAULT_THEME)).toEqual([]);
    });

    it('flags unreadable admin colour pairs', () => {
        const issues = themeContrastIssues({ ...DEFAULT_THEME, sidebar_text: '#1e293b', header_text: '#f1f5f9' });

        expect(issues.map((issue) => issue.id).sort()).toEqual(['header_text', 'sidebar_text']);
    });
});
