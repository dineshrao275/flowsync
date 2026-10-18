import { useId, useRef } from 'react';

/**
 * Shared underline tab bar with the WAI-ARIA tabs pattern: `tablist` / `tab`,
 * roving tabindex and Arrow/Home/End keys (activation follows focus, which is
 * what the page-level tab bodies expect).
 *
 * `tabs` is an array of `{ key, label, count?, icon? }`; `label` may already
 * carry a count (e.g. `Members (3)`). `active` is the current key and
 * `onChange(key)` fires on click or key navigation. Pass `panelId` (see
 * `tabPanelProps`) to wire `aria-controls` to the panel you render.
 */
export default function Tabs({ tabs, active, onChange, label, panelId, className = '' }) {
    const baseId = useId();
    const refs = useRef({});

    function move(from, step) {
        const next = (from + step + tabs.length) % tabs.length;
        const key = tabs[next].key;
        onChange(key);
        refs.current[key]?.focus();
    }

    function onKeyDown(event, index) {
        const map = { ArrowRight: 1, ArrowLeft: -1 };
        if (event.key in map) {
            event.preventDefault();
            move(index, map[event.key]);
        } else if (event.key === 'Home') {
            event.preventDefault();
            move(-1, 1);
        } else if (event.key === 'End') {
            event.preventDefault();
            move(0, -1);
        }
    }

    return (
        <div role="tablist" aria-label={label} className={`flex gap-1 overflow-x-auto overflow-y-hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden border-b border-line ${className}`}>
            {tabs.map((tab, index) => {
                const selected = active === tab.key;

                return (
                    <button
                        key={tab.key}
                        ref={(node) => { refs.current[tab.key] = node; }}
                        id={`${baseId}-${tab.key}`}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        aria-controls={panelId}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(tab.key)}
                        onKeyDown={(event) => onKeyDown(event, index)}
                        className={`-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition ${selected
                                ? 'border-[var(--accent)] text-[var(--accent-soft-text)]'
                                : 'border-transparent text-fg-muted hover:border-line-strong hover:text-fg'
                            }`}
                    >
                        {tab.icon}
                        {tab.label}
                        {tab.count != null && (
                            <span className="rounded-full bg-surface-sunken px-1.5 text-caption text-fg-muted">{tab.count}</span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
