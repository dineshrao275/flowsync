import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { CheckCircle, FolderSimple, GitBranch, UsersThree } from '@phosphor-icons/react';
import Card from '../ui/Card';
import Icon from '../ui/Icon';

/**
 * What to do once GitHub is connected. Repositories are linked per project and nothing
 * shows on a task until that link exists, so the page spells the path out.
 */
export default function GitNextSteps({ canManage }) {
    const { t } = useTranslation();
    const steps = [
        { key: 'connected', icon: CheckCircle, done: true },
        { key: 'link', icon: FolderSimple, action: <Link to="/projects" className="font-medium text-[var(--accent-soft-text)] hover:underline">{t('admin.git.next.link.cta')}</Link> },
        { key: 'work', icon: GitBranch },
        ...(canManage ? [{ key: 'identities', icon: UsersThree, action: <a href="#git-identities" className="font-medium text-[var(--accent-soft-text)] hover:underline">{t('admin.git.next.identities.cta')}</a> }] : []),
    ];

    return (
        <section aria-labelledby="git-next-title">
            <Card dense>
                <h3 id="git-next-title" className="text-h3 font-semibold text-fg">{t('admin.git.next.title')}</h3>
                <p className="mt-1 text-caption text-fg-muted">{t('admin.git.next.subtitle')}</p>
                <ol className="mt-4 space-y-4">
                    {steps.map((step, index) => (
                        <li key={step.key} className="flex gap-3">
                            <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${step.done ? 'bg-success-soft text-success' : 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'}`}>
                                <Icon as={step.icon} size="md" weight={step.done ? 'fill' : 'regular'} />
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-fg">
                                    <span className="mr-1 text-fg-muted">{index + 1}.</span>
                                    {t(`admin.git.next.${step.key}.title`)}
                                </p>
                                <p className="mt-0.5 text-sm text-fg-muted">{t(`admin.git.next.${step.key}.body`)}</p>
                                {step.action && <p className="mt-1 text-sm">{step.action}</p>}
                            </div>
                        </li>
                    ))}
                </ol>
            </Card>
        </section>
    );
}
