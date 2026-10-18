import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Funnel, X } from '@phosphor-icons/react';
import Icon from '../ui/Icon';
import { fieldClassCompact } from '../ui/fieldStyles';
import Button from '../ui/Button';
import Input from '../ui/Input';
import FloatingPanel from '../ui/FloatingPanel';
import SavedFilters from './SavedFilters';

function JqlBox({ value, error, onCommit }) {
    const { t } = useTranslation();
    const [text, setText] = useState(value || '');

    // Follow outside changes (a saved filter applied, Clear pressed).
    useEffect(() => setText(value || ''), [value]);

    return (
        <div className="w-full">
            <label htmlFor="filter-jql" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.jql')}</label>
            <input
                id="filter-jql"
                type="text"
                spellCheck={false}
                value={text}
                placeholder={t('tms.filters.jqlPlaceholder')}
                aria-invalid={error ? 'true' : undefined}
                aria-describedby="filter-jql-help"
                onChange={(e) => setText(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') onCommit(text.trim()); }}
                onBlur={() => { if (text.trim() !== (value || '')) onCommit(text.trim()); }}
                className={`${fieldClassCompact} font-mono ${error ? 'border-red-400' : ''}`}
            />
            <p id="filter-jql-help" className={`mt-1 text-xs ${error ? 'text-red-600' : 'text-gray-500'}`} role={error ? 'alert' : undefined}>
                {error || t('tms.filters.jqlHelp')}
            </p>
        </div>
    );
}

export default function FiltersBar({ filters, options, onChange, projectId = null, canShareFilters = false, jqlError = null }) {
    const { t } = useTranslation();
    function set(key, value) {
        onChange({ ...filters, [key]: value || null });
    }

    function clear() {
        onChange({});
    }

    const [open, setOpen] = useState(false);
    const anchorRef = useRef(null);
    const panelRef = useRef(null);

    // The panel floats over the board, so close it on an outside press or Escape.
    useEffect(() => {
        if (!open) return undefined;
        const onDown = (e) => {
            const inCalendar = e.target.closest?.('.react-datepicker-popper');
            if (!anchorRef.current?.contains(e.target) && !panelRef.current?.contains(e.target) && !inCalendar) setOpen(false);
        };
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const activeCount = Object.entries(filters).filter(([key, value]) => key !== 'q' && value !== '' && value != null).length;
    const hasAny = activeCount > 0 || Boolean(filters.q);

    return (
        <div ref={anchorRef} className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
            <div className="w-full sm:w-64">
                <label htmlFor="filter-q" className="sr-only">{t('tms.filters.search')}</label>
                <input
                    id="filter-q"
                    type="text"
                    placeholder={t('tms.filters.searchPlaceholder')}
                    value={filters.q || ''}
                    onChange={(e) => set('q', e.target.value)}
                    className={fieldClassCompact}
                />
            </div>
            <Button
                type="button"
                size="sm"
                variant="secondary"
                aria-expanded={open}
                aria-controls="task-filter-panel"
                onClick={() => setOpen((v) => !v)}
            >
                <Icon as={Funnel} size="sm" weight={activeCount ? 'fill' : 'regular'} className="mr-1.5" />
                {t('tms.filters.title')}
                {activeCount > 0 && (
                    <span className="ml-1.5 rounded-full bg-[var(--accent)] px-1.5 text-xs font-semibold text-[var(--accent-contrast)]">
                        <span className="sr-only">{t('tms.filters.activeCount', { count: activeCount })}</span>
                        <span aria-hidden="true">{activeCount}</span>
                    </span>
                )}
            </Button>
            {projectId && options.savedFilters && (
                <SavedFilters projectId={projectId} filters={filters} onApply={(next) => onChange(next)} canShare={canShareFilters} />
            )}
            {hasAny && (
                <Button type="button" size="sm" variant="ghost" onClick={clear}>
                    <Icon as={X} size="sm" className="mr-1" />
                    {t('tms.filters.clear')}
                </Button>
            )}
        {open && (
        <FloatingPanel
            ref={panelRef}
            anchorRef={anchorRef}
            align="left"
            id="task-filter-panel"
            className="max-h-[75vh] w-[min(44rem,calc(100vw-1.5rem))] overflow-y-auto rounded-xl border border-line bg-surface p-4 shadow-popover animate-scale-in"
        >
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 [&_select]:w-full [&_input]:w-full">
            {options.jql && <div className="sm:col-span-2 lg:col-span-3"><JqlBox value={filters.jql} error={jqlError} onCommit={(v) => set('jql', v)} /></div>}
            <div>
                <label htmlFor="filter-status" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.status')}</label>
                <select id="filter-status" className={fieldClassCompact} value={filters.status_id || ''} onChange={(e) => set('status_id', e.target.value)}>
                    <option value="">{t('tms.filters.allStatuses')}</option>
                    {options.statuses.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </select>
            </div>
            <div>
                <label htmlFor="filter-priority" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.priority')}</label>
                <select id="filter-priority" className={fieldClassCompact} value={filters.priority_id || ''} onChange={(e) => set('priority_id', e.target.value)}>
                    <option value="">{t('tms.filters.allPriorities')}</option>
                    {options.priorities.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                </select>
            </div>
            <div>
                <label htmlFor="filter-assignee" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.assignee')}</label>
                <select id="filter-assignee" className={fieldClassCompact} value={filters.assignee_id || ''} onChange={(e) => set('assignee_id', e.target.value)}>
                    <option value="">{t('tms.filters.everyone')}</option>
                    {options.assignees.map((u) => (
                        <option key={u.id} value={u.id}>
                            {u.name}
                        </option>
                    ))}
                </select>
            </div>
            {options.sprints && (
                <div>
                    <label htmlFor="filter-sprint" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.sprint')}</label>
                    <select id="filter-sprint" className={fieldClassCompact} value={filters.sprint || ''} onChange={(e) => set('sprint', e.target.value)}>
                        <option value="">{t('tms.filters.allWork')}</option>
                        <option value="active">{t('tms.filters.activeSprint')}</option>
                        <option value="none">{t('tms.filters.backlog')}</option>
                    </select>
                </div>
            )}
            {options.labels?.length > 0 && (
                <div>
                    <label htmlFor="filter-label" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.label')}</label>
                    <select id="filter-label" className={fieldClassCompact} value={filters.label_id || ''} onChange={(e) => set('label_id', e.target.value)}>
                        <option value="">{t('tms.filters.anyLabel')}</option>
                        {options.labels.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {options.issue_types?.length > 0 && (
                <div>
                    <label htmlFor="filter-type" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.type')}</label>
                    <select id="filter-type" className={fieldClassCompact} value={filters.issue_type_id || ''} onChange={(e) => set('issue_type_id', e.target.value)}>
                        <option value="">{t('tms.filters.allTypes')}</option>
                        {options.issue_types.map((t) => (
                            <option key={t.id} value={t.id}>
                                {t.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {options.versions?.length > 0 && (
                <div>
                    <label htmlFor="filter-version" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.version')}</label>
                    <select id="filter-version" className={fieldClassCompact} value={filters.version_id || ''} onChange={(e) => set('version_id', e.target.value)}>
                        <option value="">{t('tms.filters.allVersions')}</option>
                        {options.versions.map((v) => (
                            <option key={v.id} value={v.id}>
                                {v.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {options.components?.length > 0 && (
                <div>
                    <label htmlFor="filter-component" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.component')}</label>
                    <select id="filter-component" className={fieldClassCompact} value={filters.component_id || ''} onChange={(e) => set('component_id', e.target.value)}>
                        <option value="">{t('tms.filters.allComponents')}</option>
                        {options.components.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <Input
                label={t('tms.filters.dueFrom')}
                labelClassName="mb-1 block text-xs font-medium text-gray-600"
                type="date"
                compact
                value={filters.due_from || ''}
                onChange={(e) => set('due_from', e.target.value)}
            />
            <Input
                label={t('tms.filters.dueTo')}
                labelClassName="mb-1 block text-xs font-medium text-gray-600"
                type="date"
                compact
                value={filters.due_to || ''}
                onChange={(e) => set('due_to', e.target.value)}
            />
          </div>
          <div className="mt-3 flex justify-end gap-2 border-t border-line pt-3">
            {hasAny && <Button type="button" size="sm" variant="ghost" onClick={clear}>{t('tms.filters.clear')}</Button>}
            <Button type="button" size="sm" onClick={() => setOpen(false)}>{t('tms.filters.done')}</Button>
          </div>
        </FloatingPanel>
        )}
        </div>
    );
}