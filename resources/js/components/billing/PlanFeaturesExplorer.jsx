import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CaretDown, Check, LockSimple, MagnifyingGlass, X } from '@phosphor-icons/react';
import Badge from '../ui/Badge';
import {
    MODULE_CATALOG,
    MODULE_CATEGORIES,
    getModuleMeta,
    getLocalizedModuleLabel,
    getLocalizedModuleDescription,
} from '../../utils/subscriptionCatalog';

export default function PlanFeaturesExplorer({
    includedModules = [],
    availableModules = [],
    onComparePlans,
    defaultExpanded = false,
}) {
    const { t } = useTranslation();
    const [isExpanded, setIsExpanded] = useState(defaultExpanded);
    const [searchQuery, setSearchQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState('all'); // 'all' | 'included' | 'locked'
    const [categoryFilter, setCategoryFilter] = useState('all'); // 'all' | 'tms' | 'hrms' | 'platform'

    // Use availableModules if populated, otherwise all catalog keys
    const catalogKeys = useMemo(() => {
        const set = new Set([...availableModules, ...Object.keys(MODULE_CATALOG)]);
        // Ensure consistent order: included first, then by category (tms, hrms, platform)
        const allKeys = Array.from(set);
        return allKeys.sort((a, b) => {
            const incA = includedModules.includes(a);
            const incB = includedModules.includes(b);
            if (incA && !incB) return -1;
            if (!incA && incB) return 1;
            const metaA = getModuleMeta(a);
            const metaB = getModuleMeta(b);
            if (metaA.category !== metaB.category) {
                const order = { tms: 1, hrms: 2, platform: 3 };
                return (order[metaA.category] || 99) - (order[metaB.category] || 99);
            }
            return metaA.defaultLabel.localeCompare(metaB.defaultLabel);
        });
    }, [availableModules, includedModules]);

    const moduleItems = useMemo(() => {
        return catalogKeys.map((key) => {
            const meta = getModuleMeta(key);
            const isIncluded = includedModules.includes(key);
            const label = getLocalizedModuleLabel(t, key);
            const description = getLocalizedModuleDescription(t, key);

            return {
                key,
                meta,
                isIncluded,
                label,
                description,
                category: meta.category,
                group: meta.group,
            };
        });
    }, [catalogKeys, includedModules, t]);

    const includedCount = useMemo(() => moduleItems.filter((m) => m.isIncluded).length, [moduleItems]);
    const lockedCount = moduleItems.length - includedCount;

    const filteredItems = useMemo(() => {
        const q = searchQuery.trim().toLowerCase();
        return moduleItems.filter((item) => {
            if (statusFilter === 'included' && !item.isIncluded) return false;
            if (statusFilter === 'locked' && item.isIncluded) return false;
            if (categoryFilter !== 'all' && item.category !== categoryFilter) return false;

            if (q) {
                const matchName = item.label.toLowerCase().includes(q);
                const matchDesc = item.description.toLowerCase().includes(q);
                const matchGroup = item.group.toLowerCase().includes(q);
                const matchKey = item.key.toLowerCase().includes(q);
                if (!matchName && !matchDesc && !matchGroup && !matchKey) {
                    return false;
                }
            }
            return true;
        });
    }, [moduleItems, statusFilter, categoryFilter, searchQuery]);

    const handleClearFilters = () => {
        setSearchQuery('');
        setStatusFilter('all');
        setCategoryFilter('all');
    };

    return (
        <section className="rounded-2xl border border-gray-200/80 bg-white p-5 shadow-xs dark:border-gray-800 dark:bg-gray-900/60 sm:p-6">
            {/* Header with Title, Counter Badges, and Expand/Collapse Toggle */}
            <div
                role="button"
                tabIndex={0}
                aria-expanded={isExpanded}
                onClick={() => setIsExpanded((prev) => !prev)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        setIsExpanded((prev) => !prev);
                    }
                }}
                className="flex cursor-pointer select-none flex-col gap-3 transition-colors sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h3 className="text-base font-bold text-gray-900 dark:text-gray-100 sm:text-lg">
                        {t('admin.subscription.featuresHeading')}
                    </h3>
                    <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400 sm:text-sm">
                        {t('admin.subscription.featuresSubtitle')}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge tone="success" className="px-2.5 py-1 text-xs font-semibold">
                        {t('admin.subscription.unlockedCount', { count: includedCount })}
                    </Badge>
                    {lockedCount > 0 && (
                        <Badge tone="neutral" className="px-2.5 py-1 text-xs font-semibold">
                            {t('admin.subscription.lockedCount', { count: lockedCount })}
                        </Badge>
                    )}
                    <span className="ml-1 inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50/80 px-2.5 py-1 text-xs font-semibold text-gray-700 transition-colors hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        <span>
                            {isExpanded
                                ? t('admin.subscription.collapseFeatures', { defaultValue: 'Collapse' })
                                : t('admin.subscription.expandFeatures', { defaultValue: 'Expand' })}
                        </span>
                        <CaretDown
                            className={`h-3.5 w-3.5 transition-transform duration-200 ${isExpanded ? 'rotate-180' : ''}`}
                            weight="bold"
                        />
                    </span>
                </div>
            </div>

            {/* Collapsible Content */}
            {isExpanded && (
                <>
                    {/* Filter and Search Controls */}
                    <div className="mt-5 flex flex-col gap-3.5 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            {/* Search Input */}
                            <div className="relative flex-1 max-w-md">
                                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                                    <MagnifyingGlass className="h-4 w-4" weight="bold" />
                                </div>
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder={t('admin.subscription.searchFeaturesPlaceholder')}
                                    className="w-full rounded-lg border border-gray-200 bg-gray-50/60 py-2 pl-9 pr-8 text-xs text-gray-900 placeholder-gray-400 transition-colors focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-100 dark:placeholder-gray-500 dark:focus:border-indigo-400 dark:focus:bg-gray-800"
                                />
                                {searchQuery && (
                                    <button
                                        type="button"
                                        onClick={() => setSearchQuery('')}
                                        className="absolute inset-y-0 right-0 flex items-center pr-2.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                    >
                                        <X className="h-3.5 w-3.5" weight="bold" />
                                    </button>
                                )}
                            </div>

                            {/* Status Toggle Buttons */}
                            <div className="inline-flex rounded-lg border border-gray-200 bg-gray-50/80 p-0.5 dark:border-gray-700 dark:bg-gray-800/80">
                                <button
                                    type="button"
                                    onClick={() => setStatusFilter('all')}
                                    className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                        statusFilter === 'all'
                                            ? 'bg-white text-gray-900 shadow-xs dark:bg-gray-700 dark:text-gray-100'
                                            : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200'
                                    }`}
                                >
                                    {t('admin.subscription.filterAll')} ({moduleItems.length})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setStatusFilter('included')}
                                    className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                        statusFilter === 'included'
                                            ? 'bg-white text-emerald-700 shadow-xs dark:bg-gray-700 dark:text-emerald-400'
                                            : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200'
                                    }`}
                                >
                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    {t('admin.subscription.filterIncluded')} ({includedCount})
                                </button>
                                {lockedCount > 0 && (
                                    <button
                                        type="button"
                                        onClick={() => setStatusFilter('locked')}
                                        className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                            statusFilter === 'locked'
                                                ? 'bg-white text-gray-900 shadow-xs dark:bg-gray-700 dark:text-gray-100'
                                                : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200'
                                        }`}
                                    >
                                        <LockSimple className="h-3 w-3 text-gray-400" weight="bold" />
                                        {t('admin.subscription.filterLocked')} ({lockedCount})
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* Category Pills */}
                        <div className="flex flex-wrap items-center gap-1.5">
                            {MODULE_CATEGORIES.map((cat) => {
                                const active = categoryFilter === cat.id;
                                return (
                                    <button
                                        key={cat.id}
                                        type="button"
                                        onClick={() => setCategoryFilter(cat.id)}
                                        className={`rounded-full px-3 py-1 text-xs font-medium transition-all ${
                                            active
                                                ? 'bg-gray-900 text-white shadow-xs dark:bg-gray-100 dark:text-gray-900'
                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200/80 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'
                                        }`}
                                    >
                                        {t(cat.labelKey, { defaultValue: cat.defaultLabel })}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* Features Cards Grid */}
                    <div className="mt-5">
                        {filteredItems.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/50 p-8 text-center dark:border-gray-800 dark:bg-gray-800/30">
                                <MagnifyingGlass className="mx-auto h-7 w-7 text-gray-400" />
                                <p className="mt-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {t('admin.subscription.noMatchingFeatures')}
                                </p>
                                <button
                                    type="button"
                                    onClick={handleClearFilters}
                                    className="mt-3 inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                >
                                    {t('admin.subscription.clearFilters')}
                                </button>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
                                {filteredItems.map((item) => {
                                    const { isIncluded, label, description, group, category } = item;

                                    return (
                                        <div
                                            key={item.key}
                                            className={`group flex flex-col justify-between rounded-xl border p-4 transition-all duration-150 ${
                                                isIncluded
                                                    ? 'border-emerald-200/90 bg-white shadow-xs hover:border-emerald-300 dark:border-emerald-900/50 dark:bg-gray-900/70 dark:hover:border-emerald-800'
                                                    : 'border-gray-200/80 bg-white/70 shadow-2xs hover:border-gray-300 dark:border-gray-800 dark:bg-gray-900/40 dark:hover:border-gray-700'
                                            }`}
                                        >
                                            <div>
                                                <div className="flex items-start justify-between gap-2.5">
                                                    <div className="flex items-center gap-2.5 min-w-0">
                                                        <div
                                                            className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-transform group-hover:scale-105 ${
                                                                isIncluded
                                                                    ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-900'
                                                                    : 'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500 border border-gray-200/60 dark:border-gray-700'
                                                            }`}
                                                        >
                                                            {isIncluded ? (
                                                                <Check className="h-4.5 w-4.5" weight="bold" />
                                                            ) : (
                                                                <LockSimple className="h-4 w-4" weight="bold" />
                                                            )}
                                                        </div>
                                                        <div className="min-w-0">
                                                            <h4 className="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                                {label}
                                                            </h4>
                                                            <p className="truncate text-[11px] font-medium text-gray-400 dark:text-gray-500">
                                                                {group}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <Badge
                                                        tone={isIncluded ? 'success' : 'neutral'}
                                                        className="shrink-0 text-[10px] font-semibold uppercase tracking-wider"
                                                    >
                                                        {isIncluded
                                                            ? t('admin.subscription.featureStatusIncluded')
                                                            : t('admin.subscription.featureStatusLocked')}
                                                    </Badge>
                                                </div>

                                                <p className="mt-2.5 text-xs leading-relaxed text-gray-600 line-clamp-2 dark:text-gray-400">
                                                    {description}
                                                </p>
                                            </div>

                                            <div className="mt-3.5 flex items-center justify-between border-t border-gray-100 pt-2.5 dark:border-gray-800/80">
                                                <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                                    {category.toUpperCase()}
                                                </span>
                                                {isIncluded ? (
                                                    <span className="inline-flex items-center gap-1.5 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                                        {t('admin.subscription.activeOnPlan')}
                                                    </span>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        onClick={onComparePlans}
                                                        className="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:text-indigo-700 hover:underline dark:text-indigo-400 dark:hover:text-indigo-300"
                                                    >
                                                        {t('admin.subscription.viewUpgradePlans')} &rarr;
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </>
            )}
        </section>
    );
}
