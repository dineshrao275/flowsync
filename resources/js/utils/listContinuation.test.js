import { describe, expect, it } from 'vitest';
import { continueList } from './listContinuation';

const at = (value) => [value, value.length, value.length];

describe('continueList', () => {
    it('continues a numbered list with the next number', () => {
        expect(continueList(...at('1. first'))).toEqual({ value: '1. first\n2. ', caret: 12 });
        expect(continueList(...at('intro\n\n9. nine'))).toEqual({ value: 'intro\n\n9. nine\n10. ', caret: 19 });
    });

    it('keeps indentation, bullets and checklists', () => {
        expect(continueList(...at('  3. nested')).value).toBe('  3. nested\n  4. ');
        expect(continueList(...at('- a')).value).toBe('- a\n- ');
        expect(continueList(...at('* a')).value).toBe('* a\n* ');
        expect(continueList(...at('- [x] done')).value).toBe('- [x] done\n- [ ] ');
        expect(continueList(...at('- [ ] todo')).value).toBe('- [ ] todo\n- [ ] ');
    });

    it('ends the list when Enter is pressed on an empty item', () => {
        expect(continueList(...at('1. a\n2. '))).toEqual({ value: '1. a\n', caret: 5 });
        expect(continueList(...at('- a\n- '))).toEqual({ value: '- a\n', caret: 4 });
        expect(continueList(...at('- [ ] '))).toEqual({ value: '', caret: 0 });
    });

    it('splits an item at the caret and renumbers the items after it', () => {
        const value = '1. one\n2. two\n3. three';
        const caret = '1. one'.length;
        expect(continueList(value, caret, caret)).toEqual({ value: '1. one\n2. \n3. two\n4. three', caret: caret + 4 });

        const mid = '1. onetwo\n2. three';
        const split = '1. one'.length;
        expect(continueList(mid, split, split).value).toBe('1. one\n2. two\n3. three');
    });

    it('does not renumber across a gap or into another indent', () => {
        expect(continueList('1. a\n\n2. b', 4, 4).value).toBe('1. a\n2. \n\n2. b');
        expect(continueList('1. a\n  1. nested', 4, 4).value).toBe('1. a\n2. \n  1. nested');
    });

    it('leaves everything else to the browser', () => {
        expect(continueList(...at('plain text'))).toBeNull();
        expect(continueList(...at('2024. was a year'))).not.toBeNull(); // looks like a list item; documented trade-off
        expect(continueList('1. a', 1, 3)).toBeNull(); // a selection
        expect(continueList('1. a', 1, 1)).toBeNull(); // caret inside the marker
        expect(continueList('-no space', 9, 9)).toBeNull();
        expect(continueList(null, 0, 0)).toBeNull();
    });
});
