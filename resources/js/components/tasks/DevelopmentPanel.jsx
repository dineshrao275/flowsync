import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import { useResource } from '../../hooks/useResource';
import Badge from '../ui/Badge';
import EmptyState from '../ui/EmptyState';
import Resource from '../ui/Resource';
import Timestamp from '../ui/Timestamp';
import ContentSkeleton from '../ui/ContentSkeleton';

const PR_TONE = { open: 'success', draft: 'neutral', merged: 'accent', closed: 'danger' };
const CHECKS_TONE = { success: 'success', failure: 'danger', pending: 'warning', neutral: 'neutral' };

/** A small coloured dot + label for CI state; hidden when a PR has no checks at all. */
export function ChecksDot({ state }) {
    const { t } = useTranslation();
    if (!state || state === 'none') return null;

    return <Badge tone={CHECKS_TONE[state] ?? 'neutral'}>{t(`tms.development.checks.${state}`)}</Badge>;
}

function Section({ title, count, children }) {
    return (
        <section className="mb-5">
            <h3 className="mb-2 text-caption font-semibold uppercase tracking-wide text-fg-muted">
                {title} <span className="ml-1 text-fg-subtle">{count}</span>
            </h3>
            {children}
        </section>
    );
}

/** What GitHub says about this task: pull requests, commits and branches that mention its key. Read-only. */
export default function DevelopmentPanel({ task, projectId }) {
    const { t } = useTranslation();
    const res = useResource(
        (signal) => api.get(`/projects/${projectId}/tasks/${task.id}/development`, { signal }).then(({ data }) => data.development),
        [projectId, task.id],
    );

    return (
        <Resource {...res} skeleton={<ContentSkeleton size="sm" />} errorTitle={t('tms.development.loadFailed')}>
            {(dev) => {
                if (!dev.has_repository) {
                    return <EmptyState title={t('tms.development.noRepository')} description={t('tms.development.noRepositoryHint')} />;
                }
                if (!dev.pull_requests.length && !dev.commits.length && !dev.branches.length) {
                    return <EmptyState title={t('tms.development.nothingYet')} description={t('tms.development.nothingYetHint', { key: task.key })} />;
                }

                return (
                    <div>
                        {dev.pull_requests.length > 0 && (
                            <Section title={t('tms.development.pullRequests')} count={dev.pull_requests.length}>
                                <ul className="divide-y divide-line">
                                    {dev.pull_requests.map((pr) => (
                                        <li key={`${pr.repository}#${pr.number}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                            <div className="min-w-0">
                                                {pr.html_url ? <a href={pr.html_url} target="_blank" rel="noreferrer" className="text-sm font-medium text-fg hover:underline">{pr.title}</a> : <span className="text-sm font-medium text-fg">{pr.title}</span>}
                                                <p className="text-caption text-fg-muted">{pr.repository} #{pr.number}{pr.author_login ? ` · ${pr.author_login}` : ''}{pr.head_branch ? ` · ${pr.head_branch}` : ''}</p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <ChecksDot state={pr.checks_state} />
                                                <Badge tone={PR_TONE[pr.state] ?? 'neutral'}>{t(`tms.development.prState.${pr.state}`)}</Badge>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}
                        {dev.commits.length > 0 && (
                            <Section title={t('tms.development.commits')} count={dev.commits.length}>
                                <ul className="divide-y divide-line">
                                    {dev.commits.map((c) => (
                                        <li key={`${c.repository}${c.sha}`} className="py-2">
                                            <p className="truncate text-sm text-fg">{c.message}</p>
                                            <p className="text-caption text-fg-muted">
                                                {c.html_url ? <a href={c.html_url} target="_blank" rel="noreferrer" className="font-mono hover:underline">{c.sha}</a> : <span className="font-mono">{c.sha}</span>}
                                                {' · '}{c.author_login ?? c.author_name ?? '—'}{c.committed_at && <>{' · '}<Timestamp date={c.committed_at} /></>}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}
                        {dev.branches.length > 0 && (
                            <Section title={t('tms.development.branches')} count={dev.branches.length}>
                                <ul className="divide-y divide-line">
                                    {dev.branches.map((b) => (
                                        <li key={`${b.repository}${b.name}`} className="flex items-center justify-between gap-2 py-2">
                                            {b.html_url && !b.deleted ? <a href={b.html_url} target="_blank" rel="noreferrer" className="truncate font-mono text-sm text-fg hover:underline">{b.name}</a> : <span className="truncate font-mono text-sm text-fg-muted">{b.name}</span>}
                                            {b.deleted && <Badge tone="neutral">{t('tms.development.branchDeleted')}</Badge>}
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}
                    </div>
                );
            }}
        </Resource>
    );
}
