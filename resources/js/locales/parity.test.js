import { describe, expect, it } from 'vitest';

// Every dictionary file of every non-English locale must mirror English: same
// keys, same {{placeholders}}/<n> tags. A string that exists only in `en`
// renders English inside a Spanish screen — and the missing-key warning only
// fires in dev — so this is the gate that keeps a locale shippable.
const dictionaries = import.meta.glob('./*/*.json', { eager: true, import: 'default' });

function flatten(object, prefix = '') {
    return Object.entries(object).reduce((flat, [key, value]) => {
        if (value && typeof value === 'object') return { ...flat, ...flatten(value, `${prefix}${key}.`) };

        return { ...flat, [`${prefix}${key}`]: String(value) };
    }, {});
}

const tokens = (line) => (line.match(/\{\{\s*\w+\s*\}\}|<\/?\d+>|<\/?strong>/g) ?? []).map((token) => token.replace(/\s+/g, '')).sort();

const byLocale = {};
for (const [path, dictionary] of Object.entries(dictionaries)) {
    const [, locale, file] = path.match(/^\.\/([^/]+)\/([^/]+)\.json$/);
    (byLocale[locale] ??= {})[file] = flatten(dictionary);
}

const english = byLocale.en;

describe.each(Object.keys(byLocale).filter((locale) => locale !== 'en'))('%s dictionaries', (locale) => {
    it('ships every English file', () => {
        expect(Object.keys(byLocale[locale]).sort()).toEqual(Object.keys(english).sort());
    });

    it.each(Object.keys(english))('%s has the same keys as English', (file) => {
        const source = english[file];
        const target = byLocale[locale][file] ?? {};

        expect(Object.keys(target).sort()).toEqual(Object.keys(source).sort());
    });

    it.each(Object.keys(english))('%s keeps every placeholder', (file) => {
        const mismatched = Object.keys(english[file]).filter(
            (key) => tokens(english[file][key]).join() !== tokens(byLocale[locale][file]?.[key] ?? '').join(),
        );

        expect(mismatched).toEqual([]);
    });

    it.each(Object.keys(english))('%s has no empty strings', (file) => {
        const empty = Object.entries(byLocale[locale][file] ?? {}).filter(([, value]) => value.trim() === '');

        expect(empty).toEqual([]);
    });
});
