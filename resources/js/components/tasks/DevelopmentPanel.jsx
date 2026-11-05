import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Select from '../ui/Select';
import { useResource } from '../../hooks/useResource';
import Badge from '../ui/Badge';
import Resource from '../ui/Resource';
import Timestamp from '../ui/Timestamp';
import ContentSkeleton from '../ui/ContentSkeleton';
import Avatar from '../ui/Avatar';
import Icon from '../ui/Icon';
import {
    ArrowSquareOut,
    GitBranch,
    GitCommit,
    GitMerge,
    GitPullRequest,
    GithubLogo,
    Plus,
    LinkSimple,
} from '@phosphor-icons/react';

const PR_TONE = { open: 'success', draft: 'neutral', merged: 'accent', closed: 'danger' };
const CHECKS_TONE = { success: 'success', failure: 'danger', pending: 'warning', neutral: 'neutral' };

/** A small coloured dot + label for CI state; hidden when a PR has no checks at all. */
export function ChecksDot({ state }) {
    const { t } = useTranslation();
    if (!state || state === 'none') return null;

    return <Badge tone={CHECKS_TONE[state] ?? 'neutral'}>{t(`tms.development.checks.${state}`)}</Badge>;
}

// Semantic soft tiles so every state stays readable in any theme and mode.
const TILE = {
    success: 'bg-success-soft text-success',
    accent: 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]',
    danger: 'bg-[var(--danger-soft)] text-[var(--danger)]',
    neutral: 'bg-surface-sunken text-fg-muted ring-1 ring-inset ring-line',
};
const PR_ICON = { open: GitPullRequest, draft: GitPullRequest, merged: GitMerge, closed: GitPullRequest };

function IconTile({ as, tone = 'neutral' }) {
    return (
        <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${TILE[tone]}`}>
            <Icon as={as} size="md" />
        </span>
    );
}

function Section({ icon, title, count, children }) {
    return (
        <section className="mb-6">
            <h3 className="mb-2 flex items-center gap-2 text-caption font-semibold uppercase tracking-wide text-fg-muted">
                <Icon as={icon} size="sm" />
                {title}
                <span className="rounded-full bg-surface-sunken px-2 py-0.5 text-caption font-medium text-fg-muted ring-1 ring-inset ring-line">{count}</span>
            </h3>
            <ul className="space-y-2">{children}</ul>
        </section>
    );
}

const ROW = 'flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-surface p-3 transition hover:border-[var(--accent-ring)] hover:shadow-xs';

function Person({ name }) {
    if (!name) return null;

    return (
        <span className="inline-flex items-center gap-1.5">
            <Avatar name={name} size="xs" />
            {name}
        </span>
    );
}

function Mono({ icon, children }) {
    return (
        <span className="inline-flex max-w-full items-center gap-1 rounded-md bg-surface-sunken px-1.5 py-0.5 font-mono text-caption text-fg-muted ring-1 ring-inset ring-line">
            {icon && <Icon as={icon} size="xs" />}
            <span className="truncate">{children}</span>
        </span>
    );
}

function ExternalLink({ href, className = '', children }) {
    if (!href) return <span className={className}>{children}</span>;

    return (
        <a href={href} target="_blank" rel="noreferrer" className={`group/link inline-flex min-w-0 items-center gap-1 hover:underline ${className}`}>
            <span className="truncate">{children}</span>
            <Icon as={ArrowSquareOut} size="xs" className="shrink-0 opacity-0 transition group-hover/link:opacity-100 group-focus-visible/link:opacity-100" />
        </a>
    );
}

function Empty({ icon, title, description }) {
    return (
        <div className="flex flex-col items-center rounded-xl border border-dashed border-line px-6 py-10 text-center">
            <span className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[var(--accent-soft)] text-[var(--accent-soft-text)]">
                <Icon as={icon} size="lg" />
            </span>
            <p className="text-sm font-semibold text-fg">{title}</p>
            <p className="mt-1 max-w-sm text-sm text-fg-muted">{description}</p>
        </div>
    );
}

/** Creates `feature/KEY-slug` on a linked repository. Shown only to people who may (server decides `can_create_branch`). */
function CreateBranch({ dev, task, projectId, onCreated }) {
    const { t } = useTranslation();
    const toast = useToast();
    const targets = dev.repositories.filter((r) => r.sync_enabled);
    const [repoId, setRepoId] = useState(targets[0]?.id);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    async function create() {
        setBusy(true);
        setError(null);
        try {
            const { data } = await api.post(`/projects/${projectId}/repositories/${repoId}/branches`, { task_id: task.id });
            toast.success(t('tms.development.branchCreated', { name: data.branch.name }));
            onCreated();
        } catch (err) {
            setError(fieldErrors(err).form || t('tms.development.branchFailed'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="mb-4 flex flex-wrap items-center gap-2">
            {targets.length > 1 && (
                <Select aria-label={t('tms.development.repository')} value={repoId} onChange={(e) => setRepoId(Number(e.target.value))}>
                    {targets.map((r) => <option key={r.id} value={r.id}>{r.full_name}</option>)}
                </Select>
            )}
            <Button size="sm" variant="secondary" onClick={create} disabled={busy || !repoId}>
                <span className="inline-flex items-center gap-1.5"><Icon as={Plus} size="sm" />{t('tms.development.createBranch')}</span>
            </Button>
            {error && <div className="w-full"><Alert>{error}</Alert></div>}
        </div>
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
                const create = dev.can_create_branch && <CreateBranch dev={dev} task={task} projectId={projectId} onCreated={res.reload} />;
                if (!dev.has_repository) {
                    return <Empty icon={LinkSimple} title={t('tms.development.noRepository')} description={t('tms.development.noRepositoryHint')} />;
                }
                if (!dev.pull_requests.length && !dev.commits.length && !dev.branches.length) {
                    return <div>{create}<Empty icon={GithubLogo} title={t('tms.development.nothingYet')} description={t('tms.development.nothingYetHint', { key: task.key })} /></div>;
                }

                return (
                    <div>
                        {create}
                        {dev.pull_requests.length > 0 && (
                            <Section icon={GitPullRequest} title={t('tms.development.pullRequests')} count={dev.pull_requests.length}>
                                {dev.pull_requests.map((pr) => (
                                    <li key={`${pr.repository}#${pr.number}`} className={ROW}>
                                        <div className="flex min-w-0 flex-1 items-start gap-3">
                                            <IconTile as={PR_ICON[pr.state] ?? GitPullRequest} tone={PR_TONE[pr.state] === 'warning' ? 'neutral' : PR_TONE[pr.state] ?? 'neutral'} />
                                            <div className="min-w-0">
                                                <ExternalLink href={pr.html_url} className="text-sm font-medium text-fg">{pr.title}</ExternalLink>
                                                <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-caption text-fg-muted">
                                                    <span>{pr.repository} #{pr.number}</span>
                                                    <Person name={pr.author_login} />
                                                    {pr.head_branch && <Mono icon={GitBranch}>{pr.head_branch}</Mono>}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <ChecksDot state={pr.checks_state} />
                                            <Badge tone={PR_TONE[pr.state] ?? 'neutral'}>{t(`tms.development.prState.${pr.state}`)}</Badge>
                                        </div>
                                    </li>
                                ))}
                            </Section>
                        )}
                        {dev.commits.length > 0 && (
                            <Section icon={GitCommit} title={t('tms.development.commits')} count={dev.commits.length}>
                                {dev.commits.map((c) => (
                                    <li key={`${c.repository}${c.sha}`} className={ROW}>
                                        <div className="flex min-w-0 flex-1 items-start gap-3">
                                            <IconTile as={GitCommit} />
                                            <div className="min-w-0">
                                                <p className="truncate text-sm text-fg">{c.message}</p>
                                                <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-caption text-fg-muted">
                                                    {c.html_url ? <a href={c.html_url} target="_blank" rel="noreferrer" className="font-mono hover:underline">{c.sha}</a> : <span className="font-mono">{c.sha}</span>}
                                                    <Person name={c.author_login ?? c.author_name ?? '—'} />
                                                    {c.committed_at && <Timestamp date={c.committed_at} />}
                                                </p>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </Section>
                        )}
                        {dev.branches.length > 0 && (
                            <Section icon={GitBranch} title={t('tms.development.branches')} count={dev.branches.length}>
                                {dev.branches.map((br) => (
                                    <li key={`${br.repository}${br.name}`} className={ROW}>
                                        <div className="flex min-w-0 flex-1 items-center gap-3">
                                            <IconTile as={GitBranch} tone={br.deleted ? 'neutral' : 'accent'} />
                                            <div className="min-w-0">
                                                {br.html_url && !br.deleted
                                                    ? <ExternalLink href={br.html_url} className="font-mono text-sm text-fg">{br.name}</ExternalLink>
                                                    : <span className="block truncate font-mono text-sm text-fg-muted">{br.name}</span>}
                                                <p className="text-caption text-fg-muted">{br.repository}</p>
                                            </div>
                                        </div>
                                        {br.deleted && <Badge tone="neutral">{t('tms.development.branchDeleted')}</Badge>}
                                    </li>
                                ))}
                            </Section>
                        )}
                    </div>
                );
            }}
        </Resource>
    );
}
