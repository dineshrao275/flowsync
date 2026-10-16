import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowDown, ArrowUp, PencilSimple, Trash } from '@phosphor-icons/react';
import Drawer from '../ui/Drawer';
import Switch from '../ui/Switch';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Icon from '../ui/Icon';
import { fieldClass } from '../ui/fieldStyles';

function Segmented({ label, value, options, onChange }) {
    return (
        <div>
            <p className="mb-1.5 text-sm font-medium text-fg">{label}</p>
            <div role="radiogroup" aria-label={label} className="inline-flex rounded-lg border border-gray-300 p-0.5">
                {options.map((o) => (
                    <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={value === o.value}
                        onClick={() => onChange(o.value)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${value === o.value ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'text-gray-600 hover:bg-gray-100'}`}
                    >
                        {o.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

/**
 * Board settings drawer. "View" settings are personal (any member, stored per
 * user); "Columns" are the project's shared definition and only render the
 * edit/move/delete/add controls for managers.
 */
export default function ColumnSettingsDrawer({ open, onClose, statuses, prefs, onPrefs, canManage, onEdit, onMove, onDelete, onAdd }) {
    const { t } = useTranslation();
    const [name, setName] = useState('');
    const [category, setCategory] = useState('todo');

    const hidden = new Set(prefs.hidden);

    function toggleHidden(id, visible) {
        const next = new Set(hidden);
        if (visible) next.delete(id);
        else next.add(id);
        onPrefs({ hidden: [...next] });
    }

    async function add(e) {
        e.preventDefault();
        if (await onAdd({ name: name.trim(), category })) setName('');
    }

    return (
        <Drawer open={open} onClose={onClose} title={t('tms.columns.title')} subtitle={t('tms.columns.subtitle')}>
            <div className="space-y-8 p-5">
                <section className="space-y-4" aria-labelledby="board-view-h">
                    <h3 id="board-view-h" className="text-sm font-semibold text-fg">{t('tms.columns.viewTitle')}</h3>
                    <p className="text-caption text-fg-muted">{t('tms.columns.viewHint')}</p>
                    <Segmented
                        label={t('tms.columns.width')}
                        value={prefs.width}
                        onChange={(width) => onPrefs({ width })}
                        options={[
                            { value: 'compact', label: t('tms.columns.widthCompact') },
                            { value: 'default', label: t('tms.columns.widthDefault') },
                            { value: 'wide', label: t('tms.columns.widthWide') },
                        ]}
                    />
                    <Segmented
                        label={t('tms.columns.density')}
                        value={prefs.density}
                        onChange={(density) => onPrefs({ density })}
                        options={[
                            { value: 'comfortable', label: t('tms.columns.densityComfortable') },
                            { value: 'compact', label: t('tms.columns.densityCompact') },
                        ]}
                    />
                    <Switch
                        label={t('tms.columns.hideEmpty')}
                        description={t('tms.columns.hideEmptyHint')}
                        checked={prefs.hide_empty}
                        onChange={(hide_empty) => onPrefs({ hide_empty })}
                    />
                </section>

                <section className="space-y-3" aria-labelledby="board-cols-h">
                    <h3 id="board-cols-h" className="text-sm font-semibold text-fg">{t('tms.columns.columnsTitle')}</h3>
                    {!canManage && <p className="text-caption text-fg-muted">{t('tms.columns.managersOnly')}</p>}
                    <ul className="divide-y divide-line rounded-lg border border-line">
                        {statuses.map((s, i) => (
                            <li key={s.id} className="flex items-center gap-2 px-3 py-2">
                                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: s.color || '#cbd5e1' }} />
                                <span className="min-w-0 flex-1 truncate text-sm text-fg">
                                    {s.name}
                                    {s.wip_limit != null && <span className="ml-2 text-caption text-fg-muted">{t('tms.columns.wipShort', { count: s.wip_limit })}</span>}
                                </span>
                                {canManage && (
                                    <>
                                        <Button type="button" size="icon" variant="ghost" aria-label={t('tms.columns.moveUp', { name: s.name })} disabled={i === 0} onClick={() => onMove(s, i)}>
                                            <Icon as={ArrowUp} size="sm" />
                                        </Button>
                                        <Button type="button" size="icon" variant="ghost" aria-label={t('tms.columns.moveDown', { name: s.name })} disabled={i === statuses.length - 1} onClick={() => onMove(s, i + 2)}>
                                            <Icon as={ArrowDown} size="sm" />
                                        </Button>
                                        <Button type="button" size="icon" variant="ghost" aria-label={t('tms.columns.edit', { name: s.name })} onClick={() => onEdit(s)}>
                                            <Icon as={PencilSimple} size="sm" />
                                        </Button>
                                        <Button type="button" size="icon" variant="ghost" aria-label={t('tms.columns.delete', { name: s.name })} onClick={() => onDelete(s)}>
                                            <Icon as={Trash} size="sm" />
                                        </Button>
                                    </>
                                )}
                                <Switch
                                    id={`col-visible-${s.id}`}
                                    checked={!hidden.has(s.id)}
                                    onChange={(v) => toggleHidden(s.id, v)}
                                    label={<span className="sr-only">{t('tms.columns.show', { name: s.name })}</span>}
                                />
                            </li>
                        ))}
                    </ul>
                    {canManage && (
                        <form onSubmit={add} className="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <div className="flex-1">
                                <Input label={t('tms.columns.addColumn')} value={name} onChange={(e) => setName(e.target.value)} placeholder={t('tms.project.statusNamePlaceholder')} />
                            </div>
                            <select aria-label={t('tms.project.category')} className={`${fieldClass} sm:w-40`} value={category} onChange={(e) => setCategory(e.target.value)}>
                                {['todo', 'in_progress', 'done'].map((c) => (
                                    <option key={c} value={c}>{t(`tms.columns.category.${c}`)}</option>
                                ))}
                            </select>
                            <Button type="submit" disabled={!name.trim()}>{t('tms.columns.add')}</Button>
                        </form>
                    )}
                </section>
            </div>
        </Drawer>
    );
}
