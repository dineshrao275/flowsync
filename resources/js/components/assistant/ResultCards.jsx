import { Link } from 'react-router-dom';
import { ArrowUpRight } from '@phosphor-icons/react';

/**
 * Records the assistant found. Links come from the server (built from ids it just read), and are only
 * ever rendered as in-app routes: a value that is not an app path is shown as plain text.
 */
export default function ResultCards({ cards, onNavigate }) {
    if (!cards?.length) return null;

    return (
        <ul className="mt-2 space-y-1.5" aria-label="Results">
            {cards.map((card, index) => {
                const inApp = typeof card.link === 'string' && card.link.startsWith('/') && !card.link.startsWith('//');
                const body = (
                    <>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium text-fg">{card.title}</span>
                            {card.subtitle ? <span className="block truncate text-caption text-fg-muted">{card.subtitle}</span> : null}
                            {card.badges?.length ? (
                                <span className="mt-1 flex flex-wrap gap-1">
                                    {card.badges.map((badge) => (
                                        <span key={badge} className="rounded bg-surface-sunken px-1.5 py-0.5 text-caption text-fg-muted">{badge}</span>
                                    ))}
                                </span>
                            ) : null}
                        </span>
                        {inApp ? <ArrowUpRight size={16} className="mt-0.5 shrink-0 text-fg-subtle" aria-hidden="true" /> : null}
                    </>
                );
                const className = 'flex items-start gap-2 rounded-lg border border-line bg-surface px-3 py-2';

                return (
                    <li key={`${card.link}-${index}`}>
                        {inApp ? <Link to={card.link} onClick={onNavigate} className={`${className} transition-colors hover:border-[var(--accent)]`}>{body}</Link> : <div className={className}>{body}</div>}
                    </li>
                );
            })}
        </ul>
    );
}
