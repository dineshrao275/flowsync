import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Avatar from './Avatar';
import FloatingPanel from './FloatingPanel';
import { fieldClass } from './fieldStyles';

function PersonCard({ person, active, ...rest }) {
    return (
        <div
            className={`flex items-center gap-3 rounded-lg border px-3 py-2 text-left ${active ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-gray-200 bg-white'}`}
            {...rest}
        >
            <Avatar name={person.name} photoUrl={person.photo_url} size="lg" />
            <span className="min-w-0">
                <span className="block truncate text-sm font-medium text-gray-900">{person.name}</span>
                {person.email && <span className="block truncate text-xs text-gray-500">{person.email}</span>}
            </span>
        </div>
    );
}

/**
 * Search-as-you-type picker for people: suggestions are cards (avatar, full name, email) and the
 * chosen person stays shown as a card. `users` is `[{id, name, email, photo_url?}]`; `value` is an id
 * (or ''), `onChange(id)` gets the new id ('' when cleared).
 */
export default function MemberPicker({ users, value, onChange, placeholder, emptyText, id, disabled = false, maxDisplay = 250 }) {
    const { t } = useTranslation();
    const listId = useId();
    const rootRef = useRef(null);
    const panelRef = useRef(null);
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);

    const selected = users.find((u) => String(u.id) === String(value)) ?? null;
    const matches = useMemo(() => {
        const q = query.trim().toLowerCase();
        const filtered = q ? users.filter((u) => `${u.name} ${u.email ?? ''}`.toLowerCase().includes(q)) : users;
        return maxDisplay ? filtered.slice(0, maxDisplay) : filtered;
    }, [users, query, maxDisplay]);

    useEffect(() => {
        const onDown = (e) => { if (!rootRef.current?.contains(e.target) && !panelRef.current?.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, []);

    function choose(person) {
        onChange(person.id);
        setQuery('');
        setOpen(false);
    }

    function onKeyDown(e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); setOpen(true); setActive((i) => Math.min(i + 1, matches.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
        else if (e.key === 'Enter' && open && matches[active]) { e.preventDefault(); choose(matches[active]); }
        else if (e.key === 'Escape') setOpen(false);
    }

    if (selected) {
        return (
            <div className="flex items-center gap-2">
                <div className="min-w-0 flex-1"><PersonCard person={selected} active /></div>
                <button type="button" disabled={disabled} onClick={() => onChange('')} className="rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100">
                    {t('ui.change')}
                </button>
            </div>
        );
    }

    return (
        <div ref={rootRef} className="relative">
            <input
                id={id}
                disabled={disabled}
                type="text"
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                autoComplete="off"
                className={fieldClass}
                placeholder={placeholder ?? t('ui.searchPeople')}
                value={query}
                onChange={(e) => { setQuery(e.target.value); setOpen(true); setActive(0); }}
                onFocus={() => setOpen(true)}
                onKeyDown={onKeyDown}
            />
            {open && (
                <FloatingPanel ref={panelRef} anchorRef={rootRef} align="left" matchWidth id={listId} role="listbox" className="max-h-72 space-y-1.5 overflow-auto rounded-xl border border-gray-200 bg-white p-2 shadow-popover">
                    {matches.length === 0 && <p className="px-2 py-3 text-sm text-gray-500">{emptyText ?? t('ui.noMatches')}</p>}
                    {matches.map((person, index) => (
                        <button
                            key={person.id}
                            type="button"
                            role="option"
                            aria-selected={index === active}
                            onMouseEnter={() => setActive(index)}
                            onClick={() => choose(person)}
                            className="block w-full"
                        >
                            <PersonCard person={person} active={index === active} />
                        </button>
                    ))}
                    {users.length > matches.length && (
                        <p className="px-2 py-1.5 text-center text-xs text-gray-400">
                            {t('ui.showingCount', { count: matches.length, total: users.length, defaultValue: `Showing ${matches.length} of ${users.length}. Type to search.` })}
                        </p>
                    )}
                </FloatingPanel>
            )}
        </div>
    );
}
