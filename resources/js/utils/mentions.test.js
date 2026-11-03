import { describe, expect, it } from 'vitest';
import { embedMentions, revealMentions } from './mentions';

describe('mentions', () => {
    it('joins the picked ids back in when sending, and keeps the box text clean', () => {
        const picked = [{ id: 1, name: 'Acme Corp Owner' }];

        expect(embedMentions('@Acme Corp Owner have you given me access?', picked)).toBe('@[Acme Corp Owner](user:1) have you given me access?');
    });

    it('does not depend on the order people were picked in', () => {
        const picked = [{ id: 2, name: 'Editor User' }, { id: 1, name: 'Acme Corp Owner' }];

        expect(embedMentions('@Acme Corp Owner and @Editor User', picked)).toBe('@[Acme Corp Owner](user:1) and @[Editor User](user:2)');
    });

    it('keeps two people who share a name apart, in the order they were picked', () => {
        const picked = [{ id: 7, name: 'Sam Lee' }, { id: 9, name: 'Sam Lee' }];

        expect(embedMentions('@Sam Lee then @Sam Lee', picked)).toBe('@[Sam Lee](user:7) then @[Sam Lee](user:9)');
    });

    it('prefers the longer name and respects word boundaries', () => {
        const picked = [{ id: 1, name: 'Ada' }, { id: 2, name: 'Ada Lovelace' }];

        expect(embedMentions('@Ada Lovelace, and @Ada.', picked)).toBe('@[Ada Lovelace](user:2), and @[Ada](user:1).');
        expect(embedMentions('@Adam', [{ id: 1, name: 'Ada' }])).toBe('@Adam');
    });

    it('ignores a picked mention that was deleted and leaves hand-typed ones alone', () => {
        expect(embedMentions('no mention left, hi @someone', [{ id: 1, name: 'Acme Corp Owner' }])).toBe('no mention left, hi @someone');
        expect(embedMentions('plain', [])).toBe('plain');
    });

    it('is not fooled by regex characters in a name', () => {
        expect(embedMentions('@A.B (Ops) ok', [{ id: 3, name: 'A.B (Ops)' }])).toBe('@[A.B (Ops)](user:3) ok');
    });

    it('turns a stored comment back into clean text plus the picked ids', () => {
        const { text, mentions } = revealMentions('Hi @[Acme Corp Owner](user:1) and @[Editor User](user:2)!');

        expect(text).toBe('Hi @Acme Corp Owner and @Editor User!');
        expect(mentions).toEqual([{ id: 1, name: 'Acme Corp Owner' }, { id: 2, name: 'Editor User' }]);
        expect(embedMentions(text, mentions)).toBe('Hi @[Acme Corp Owner](user:1) and @[Editor User](user:2)!');
    });
});
