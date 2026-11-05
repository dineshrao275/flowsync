import { useTranslation } from 'react-i18next';
import { useDevelopmentRepositories } from '../../hooks/DevelopmentSummary';
import Badge from '../ui/Badge';
import Card from '../ui/Card';

/** Project overview: the repositories this project follows and the latest pull request and commit on each. */
export default function RepositoriesOverview() {
    const { t } = useTranslation();
    const repositories = useDevelopmentRepositories();
    if (!repositories?.length) return null;

    return (
        <Card title={t('tms.development.overviewTitle')}>
            <ul className="space-y-3">
                {repositories.map((repo) => (
                    <li key={repo.id} className="text-sm">
                        <a href={repo.html_url} target="_blank" rel="noreferrer" className="font-medium text-fg hover:underline">{repo.full_name}</a>
                        {repo.last_pull_request && (
                            <p className="mt-1 flex items-center gap-2 text-caption text-fg-muted">
                                <Badge tone="neutral">{t(`tms.development.prState.${repo.last_pull_request.state}`)}</Badge>
                                <span className="truncate">#{repo.last_pull_request.number} {repo.last_pull_request.title}</span>
                            </p>
                        )}
                        {repo.last_commit && <p className="mt-1 truncate text-caption text-fg-muted"><span className="font-mono">{repo.last_commit.sha}</span> {repo.last_commit.message}</p>}
                    </li>
                ))}
            </ul>
        </Card>
    );
}
