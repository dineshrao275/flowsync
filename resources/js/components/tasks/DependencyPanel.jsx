import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Badge from '../ui/Badge';
import Alert from '../ui/Alert';
import ContentSkeleton from '../ui/ContentSkeleton';
import Button from '../ui/Button';
import { useConfirm } from '../ui/ConfirmDialog';

export default function DependencyPanel({ task, projectId, canManage, allTasks }) {
    const { t } = useTranslation();
    const confirm = useConfirm();
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState('');
    const [linkType, setLinkType] = useState('blocks');
    const [busy, setBusy] = useState(false);
    const [formErrors, setFormErrors] = useState({});

    function fetchDependencies() {
        api.get(`/projects/${projectId}/tasks/${task.id}/dependencies`)
            .then(({ data }) => {
                setData(data);
                setError(null);
            })
            .catch(() => setError(t('tms.dependencies.loadFailed')));
    }

    useEffect(() => {
        fetchDependencies();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId, task.id]);

    function add(e) {
        e.preventDefault();
        if (!selected) return;
        setBusy(true);
        setFormErrors({});
        api.post(`/projects/${projectId}/tasks/${task.id}/dependencies`, {
            depends_on_task_id: Number(selected),
            type: linkType,
        })
            .then(() => {
                setSelected('');
                fetchDependencies();
            })
            .catch((err) => setFormErrors(fieldErrors(err)))
            .finally(() => setBusy(false));
    }

    async function remove(id) {
        if (!(await confirm({
            title: t('tms.dependencies.removeTitle'),
            message: t('tms.dependencies.removeMessage'),
            variant: 'danger',
            confirmLabel: t('tms.dependencies.remove'),
        }))) return;

        api.delete(`/projects/${projectId}/tasks/${task.id}/dependencies/${id}`)
            .then(fetchDependencies)
            .catch(() => { });
    }

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!data) {
        return (
            <ContentSkeleton size="md" />
        );
    }

    const candidates = allTasks.filter((t) => t.id !== task.id);

    function DepRow({ dependency }) {
        const name = dependency.task
            ? `${dependency.task.key} · ${dependency.task.title}`
            : dependency.dependsOn
                ? `${dependency.dependsOn.key} · ${dependency.dependsOn.title}`
                : t('tms.dependencies.taskFallback', { id: dependency.depends_on_task_id });
        return (
            <li className="flex items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2">
                <span className="text-sm text-gray-700">{name}</span>
                {canManage && (
                    <button
                        type="button"
                        className="text-xs text-gray-400 transition hover:text-red-600"
                        onClick={() => remove(dependency.id)}
                    >
                        {t('tms.dependencies.remove')}
                    </button>
                )}
            </li>
        );
    }

    return (
        <div className="space-y-5">
            <div>
                <p className="mb-2 text-sm font-medium text-gray-700">
                    {t('tms.dependencies.blockedBy')} <Badge>{data.blocked_by.length}</Badge>
                </p>
                {data.blocked_by.length === 0 ? (
                    <p className="text-sm text-gray-400">{t('tms.dependencies.emptyBlocked')}</p>
                ) : (
                    <ul className="space-y-2">
                        {data.blocked_by.map((dependency) => (
                            <DepRow key={dependency.id} dependency={dependency} />
                        ))}
                    </ul>
                )}
            </div>

            <div>
                <p className="mb-2 text-sm font-medium text-gray-700">
                    {t('tms.dependencies.blocks')} <Badge>{data.blocks.length}</Badge>
                </p>
                {data.blocks.length === 0 ? (
                    <p className="text-sm text-gray-400">{t('tms.dependencies.emptyBlocks')}</p>
                ) : (
                    <ul className="space-y-2">
                        {data.blocks.map((dependency) => (
                            <DepRow key={dependency.id} dependency={dependency} />
                        ))}
                    </ul>
                )}
            </div>

            {data.links?.length > 0 && (
                <div>
                    <p className="mb-2 text-sm font-medium text-gray-700">{t('tms.dependencies.links')} <Badge>{data.links.length}</Badge></p>
                    <ul className="space-y-2">
                        {data.links.map((link) => (
                            <li key={`${link.direction}-${link.id}`} className="flex items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2 text-sm text-gray-700">
                                <span><span className="text-gray-500">{t(`tms.dependencies.linkWording.${link.type}.${link.direction}`)}</span> {link.task ? `${link.task.key} · ${link.task.title}` : ''}</span>
                                {canManage && <button type="button" className="text-xs text-gray-400 transition hover:text-red-600" onClick={() => remove(link.id)}>{t('tms.dependencies.remove')}</button>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {canManage && (
                <form onSubmit={add} className="space-y-2 border-t border-gray-100 pt-4">
                    <label className="block text-sm font-medium text-gray-700">{t('tms.dependencies.addTitle')}</label>
                    <select aria-label={t('tms.dependencies.typeLabel')} className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-[var(--accent)] focus:outline-none" value={linkType} onChange={(e) => setLinkType(e.target.value)}>
                        {['blocks', 'related_to', 'duplicates', 'clones'].map((ty) => <option key={ty} value={ty}>{t(`tms.dependencies.types.${ty}`)}</option>)}
                    </select>
                    <div className="flex gap-2">
                        <select
                            className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-[var(--accent)] focus:outline-none"
                            value={selected}
                            onChange={(e) => setSelected(e.target.value)}
                        >
                            <option value="">{t('tms.dependencies.selectTask')}</option>
                            {candidates.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.key} · {t.title}
                                </option>
                            ))}
                            {candidates.length === 0 && <option value="">{t('tms.dependencies.noOtherTasks')}</option>}
                        </select>
                        <button
                            type="button"
                            className="rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-500 hover:bg-gray-50"
                            onClick={() => setSelected('')}
                            title={t('tms.dependencies.clear')}
                        >
                            ✕
                        </button>
                    </div>
                    {formErrors.depends_on_task_id && <Alert>{formErrors.depends_on_task_id}</Alert>}
                    {formErrors.form && <Alert>{formErrors.form}</Alert>}
                    <Button size="sm" type="submit" loading={busy} disabled={!selected}>
                        {t('tms.dependencies.add')}
                    </Button>
                </form>
            )}
        </div>
    );
}