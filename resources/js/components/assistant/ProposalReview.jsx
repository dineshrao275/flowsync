import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Plus, Trash } from '@phosphor-icons/react';
import Button from '../ui/Button';
import Checkbox from '../ui/Checkbox';
import Select from '../ui/Select';
import { fieldClassCompact } from '../ui/fieldStyles';

function ItemResult({ result }) {
    const { t } = useTranslation();
    if (!result) return null;

    if (result.status === 'created') {
        return <Link to={result.link} className="text-sm font-medium text-[var(--accent)] hover:underline">{t('assistant.documents.created', { key: result.key })}</Link>;
    }

    return <p className="text-sm text-danger-fg" role="alert">{t('assistant.documents.failed')}: {result.error}</p>;
}

/** The editable preview. Rows already created are read-only (the server freezes them too). */
export default function ProposalReview({ proposal, items, onEdit, onRemove, onAdd, readOnly = false }) {
    const { t } = useTranslation();
    const selected = items.filter((item) => item.selected && item.result?.status !== 'created').length;

    return (
        <div className="space-y-3">
            {proposal.truncated ? <p className="rounded-lg border border-warning-line bg-warning-soft px-3 py-2 text-sm text-warning" role="status">{t('assistant.documents.truncated')}</p> : null}
            <ul className="space-y-3" aria-label={t('assistant.documents.proposedTasks')}>
                {items.map((item, index) => {
                    const locked = readOnly || item.result?.status === 'created';
                    const n = index + 1;

                    return (
                        <li key={item.id} className={`rounded-lg border p-3 ${item.result?.status === 'created' ? 'border-success-line bg-success-soft' : 'border-line bg-surface'}`}>
                            <div className="flex items-start gap-3">
                                <Checkbox checked={item.selected} disabled={locked} onChange={(e) => onEdit(item.id, { selected: e.target.checked })} aria-label={t('assistant.documents.include', { n })} />
                                <div className="min-w-0 flex-1 space-y-2">
                                    <input
                                        value={item.title}
                                        disabled={locked}
                                        maxLength={255}
                                        onChange={(e) => onEdit(item.id, { title: e.target.value })}
                                        aria-label={t('assistant.documents.title', { n })}
                                        placeholder={t('assistant.documents.titlePlaceholder')}
                                        className={fieldClassCompact}
                                    />
                                    <textarea
                                        value={item.description || ''}
                                        disabled={locked}
                                        rows={3}
                                        maxLength={6000}
                                        onChange={(e) => onEdit(item.id, { description: e.target.value })}
                                        aria-label={t('assistant.documents.description', { n })}
                                        placeholder={t('assistant.documents.descriptionPlaceholder')}
                                        className={`${fieldClassCompact} resize-y`}
                                    />
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Select value={item.priority || ''} disabled={locked} onChange={(e) => onEdit(item.id, { priority: e.target.value || null })} aria-label={t('assistant.documents.priority', { n })} className="w-48">
                                            <option value="">{t('assistant.documents.defaultPriority')}</option>
                                            {proposal.priorities.map((p) => <option key={p.slug} value={p.slug}>{p.name}</option>)}
                                        </Select>
                                        <input
                                            type="number"
                                            min="0"
                                            max="999.99"
                                            step="0.5"
                                            value={item.story_points ?? ''}
                                            disabled={locked}
                                            onChange={(e) => onEdit(item.id, { story_points: e.target.value === '' ? null : Number(e.target.value) })}
                                            aria-label={t('assistant.documents.points', { n })}
                                            placeholder={t('assistant.documents.pointsPlaceholder')}
                                            className={`${fieldClassCompact} !w-28`}
                                        />
                                        {item.duplicate_of ? <span className="rounded bg-warning-soft px-2 py-0.5 text-caption text-warning">{t('assistant.documents.similar', { key: item.duplicate_of })}</span> : null}
                                    </div>
                                    <ItemResult result={item.result} />
                                </div>
                                {!locked ? (
                                    <button type="button" onClick={() => onRemove(item.id)} aria-label={t('assistant.documents.remove', { n })} className="rounded p-1.5 text-fg-subtle hover:bg-surface-sunken hover:text-danger-fg">
                                        <Trash size={16} aria-hidden="true" />
                                    </button>
                                ) : null}
                            </div>
                        </li>
                    );
                })}
            </ul>
            {!readOnly ? (
                <div className="flex items-center justify-between">
                    <Button type="button" size="sm" variant="secondary" onClick={onAdd}><Plus size={14} aria-hidden="true" />{t('assistant.documents.addTask')}</Button>
                    <p className="text-sm text-fg-muted" aria-live="polite">{t('assistant.documents.selectedCount', { count: selected })}</p>
                </div>
            ) : null}
        </div>
    );
}
