import { useTranslation } from 'react-i18next';
import { fieldClassCompact } from '../ui/fieldStyles';
import Button from '../ui/Button';
import Input from '../ui/Input';

export default function FiltersBar({ filters, options, onChange }) {
    const { t } = useTranslation();
    function set(key, value) {
        onChange({ ...filters, [key]: value || null });
    }

    function clear() {
        onChange({});
    }

    return (
        <div className="flex flex-wrap items-end gap-3">
            <div className="w-56">
                <label htmlFor="filter-q" className="mb-1 block text-xs font-medium text-gray-600">{t('tms.filters.search')}</label>
                <input
                    id="filter-q"
                    type="text"
                    placeholder={t('tms.filters.searchPlaceholder')}
                    value={filters.q || ''}
                    onChange={(e) => set('q', e.target.value)}
                    className={fieldClassCompact}
                />
            </div>
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
            <Button type="button" size="sm" variant="secondary" onClick={clear}>
                {t('tms.filters.clear')}
            </Button>
        </div>
    );
}