import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { CaretDown, Check, MagnifyingGlass, Plus, X } from '@phosphor-icons/react';

/**
 * Modern, user-friendly MultiSelect component.
 * - Replaces clunky native <select multiple>
 * - Provides search, selected tag chips, select/clear all, and quick-toggle pills
 * - Keyboard accessible with click-outside dismissal
 */
export default function MultiSelect({
    label = '',
    options = [],
    value = [],
    onChange,
    placeholder = 'Select options…',
    searchPlaceholder = 'Search…',
    error = null,
    disabled = false,
    required = false,
    id = null,
    name = null,
    showQuickPills = true,
    className = '',
}) {
    const generatedId = useId();
    const selectId = id || name || generatedId;
    const containerRef = useRef(null);
    const searchInputRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');

    const normalizedOptions = useMemo(() => {
        return options.map((opt) => {
            if (typeof opt === 'string' || typeof opt === 'number') {
                return { value: String(opt), label: String(opt), description: null };
            }
            return {
                value: opt.value ?? opt.id ?? opt.slug,
                label: opt.label ?? opt.name ?? String(opt.value),
                description: opt.description ?? (opt.slug !== opt.name ? opt.slug : null),
            };
        });
    }, [options]);

    const selectedOptions = useMemo(() => {
        const valSet = new Set((value || []).map(String));
        return normalizedOptions.filter((opt) => valSet.has(String(opt.value)));
    }, [normalizedOptions, value]);

    const filteredOptions = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return normalizedOptions;
        return normalizedOptions.filter(
            (opt) =>
                opt.label.toLowerCase().includes(q) ||
                (opt.description && opt.description.toLowerCase().includes(q)) ||
                String(opt.value).toLowerCase().includes(q)
        );
    }, [normalizedOptions, search]);

    const toggleOption = useCallback(
        (optValue) => {
            if (disabled) return;
            const current = (value || []).map(String);
            const strVal = String(optValue);
            let next;
            if (current.includes(strVal)) {
                next = current.filter((v) => v !== strVal);
            } else {
                next = [...current, strVal];
            }
            onChange?.(next);
        },
        [disabled, onChange, value]
    );

    const removeOption = useCallback(
        (optValue, e) => {
            e?.stopPropagation();
            if (disabled) return;
            const current = (value || []).map(String);
            const strVal = String(optValue);
            onChange?.(current.filter((v) => v !== strVal));
        },
        [disabled, onChange, value]
    );

    const clearAll = useCallback(
        (e) => {
            e?.stopPropagation();
            if (disabled) return;
            onChange?.([]);
        },
        [disabled, onChange]
    );

    const selectAll = useCallback(
        (e) => {
            e?.stopPropagation();
            if (disabled) return;
            onChange?.(normalizedOptions.map((o) => o.value));
        },
        [disabled, normalizedOptions, onChange]
    );

    // Close on click outside
    useEffect(() => {
        if (!open) return undefined;
        function handleOutside(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', handleOutside);
        return () => document.removeEventListener('mousedown', handleOutside);
    }, [open]);

    // Focus search on open
    useEffect(() => {
        if (open && searchInputRef.current) {
            searchInputRef.current.focus();
        }
    }, [open]);

    return (
        <div className={`space-y-1.5 ${className}`} ref={containerRef}>
            {label && (
                <div className="flex items-center justify-between">
                    <label htmlFor={selectId} className="block text-sm font-medium text-gray-700">
                        {label} {required && <span className="text-red-500">*</span>}
                    </label>
                    {selectedOptions.length > 0 && (
                        <span className="text-xs text-gray-500">
                            {selectedOptions.length} {selectedOptions.length === 1 ? 'selected' : 'selected'}
                        </span>
                    )}
                </div>
            )}

            {/* Main Trigger Box */}
            <div className="relative">
                <div
                    id={selectId}
                    role="combobox"
                    aria-expanded={open}
                    aria-haspopup="listbox"
                    tabIndex={disabled ? -1 : 0}
                    onClick={() => {
                        if (!disabled) setOpen((prev) => !prev);
                    }}
                    onKeyDown={(e) => {
                        if (disabled) return;
                        if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                            e.preventDefault();
                            setOpen(true);
                        } else if (e.key === 'Escape') {
                            setOpen(false);
                        }
                    }}
                    className={`flex min-h-[42px] w-full cursor-pointer flex-wrap items-center justify-between gap-1.5 rounded-lg border bg-white px-3 py-1.5 text-sm shadow-xs transition focus:outline-none focus:ring-2 focus:ring-[var(--accent-ring)] ${
                        error
                            ? 'border-red-400 focus:border-red-500'
                            : 'border-gray-300 hover:border-gray-400 focus:border-[var(--accent)]'
                    } ${disabled ? 'cursor-not-allowed bg-gray-50 opacity-60' : ''}`}
                >
                    <div className="flex flex-1 flex-wrap items-center gap-1.5">
                        {selectedOptions.length === 0 ? (
                            <span className="select-none py-1 text-gray-400">{placeholder}</span>
                        ) : (
                            selectedOptions.map((opt) => (
                                <span
                                    key={opt.value}
                                    className="inline-flex items-center gap-1 rounded-md border border-[var(--accent-ring)] bg-[var(--accent-soft)] px-2 py-0.5 text-xs font-semibold text-[var(--accent-soft-text)] shadow-2xs animate-in fade-in zoom-in-95 duration-100"
                                >
                                    <span className="truncate max-w-[160px]">{opt.label}</span>
                                    {!disabled && (
                                        <button
                                            type="button"
                                            aria-label={`Remove ${opt.label}`}
                                            onClick={(e) => removeOption(opt.value, e)}
                                            className="rounded p-0.5 transition hover:bg-[var(--accent)] hover:text-white"
                                        >
                                            <X size={11} weight="bold" />
                                        </button>
                                    )}
                                </span>
                            ))
                        )}
                    </div>

                    <div className="flex items-center gap-1 text-gray-400">
                        {selectedOptions.length > 0 && !disabled && (
                            <button
                                type="button"
                                title="Clear all"
                                aria-label="Clear all"
                                onClick={clearAll}
                                className="rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                            >
                                <X size={13} weight="bold" />
                            </button>
                        )}
                        <span className={`transition-transform duration-200 ${open ? 'rotate-180' : ''}`}>
                            <CaretDown size={14} weight="bold" />
                        </span>
                    </div>
                </div>

                {/* Dropdown Menu */}
                {open && (
                    <div
                        role="listbox"
                        aria-multiselectable="true"
                        className="absolute left-0 right-0 top-full z-[999] mt-1.5 overflow-hidden rounded-xl border border-gray-200 bg-white p-2 shadow-xl animate-in fade-in slide-in-from-top-1 duration-150"
                    >
                        {/* Search & Actions Header */}
                        <div className="mb-2 space-y-2 border-b border-gray-100 pb-2">
                            {normalizedOptions.length > 4 && (
                                <div className="relative">
                                    <MagnifyingGlass
                                        size={14}
                                        className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400"
                                    />
                                    <input
                                        ref={searchInputRef}
                                        type="text"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder={searchPlaceholder}
                                        className="w-full rounded-md border border-gray-200 bg-gray-50 py-1.5 pl-8 pr-2.5 text-xs text-gray-800 focus:border-[var(--accent)] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[var(--accent)]"
                                    />
                                </div>
                            )}

                            <div className="flex items-center justify-between text-xs">
                                <span className="font-medium text-gray-500">
                                    {selectedOptions.length} of {normalizedOptions.length} selected
                                </span>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={selectAll}
                                        className="font-medium text-[var(--accent-soft-text)] hover:text-[var(--accent)] hover:underline"
                                    >
                                        Select all
                                    </button>
                                    <span className="text-gray-200">|</span>
                                    <button
                                        type="button"
                                        onClick={clearAll}
                                        className="text-gray-400 hover:text-gray-600 hover:underline"
                                    >
                                        Clear
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* Options List */}
                        <div className="max-h-56 overflow-y-auto space-y-0.5 pr-0.5">
                            {filteredOptions.length === 0 ? (
                                <p className="py-4 text-center text-xs text-gray-400">
                                    No matching options found
                                </p>
                            ) : (
                                filteredOptions.map((opt) => {
                                    const isSelected = (value || []).map(String).includes(String(opt.value));
                                    return (
                                        <div
                                            key={opt.value}
                                            role="option"
                                            aria-selected={isSelected}
                                            onClick={() => toggleOption(opt.value)}
                                            className={`flex cursor-pointer items-center justify-between gap-2.5 rounded-lg px-2.5 py-2 text-sm transition ${
                                                isSelected
                                                    ? 'bg-[var(--accent-soft)]/60 text-gray-900 font-medium'
                                                    : 'text-gray-700 hover:bg-gray-50'
                                            }`}
                                        >
                                            <div className="flex items-center gap-2.5 min-w-0">
                                                <div
                                                    className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border transition ${
                                                        isSelected
                                                            ? 'border-[var(--accent)] bg-[var(--accent)] text-white'
                                                            : 'border-gray-300 bg-white'
                                                    }`}
                                                >
                                                    {isSelected && <Check size={11} weight="bold" />}
                                                </div>
                                                <div className="min-w-0 truncate">
                                                    <span className="block truncate text-sm font-medium">
                                                        {opt.label}
                                                    </span>
                                                    {opt.description && (
                                                        <span className="block truncate text-[11px] text-gray-400">
                                                            {opt.description}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>

                                            {isSelected && (
                                                <span className="shrink-0 text-[11px] font-semibold text-[var(--accent-soft-text)]">
                                                    Added
                                                </span>
                                            )}
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* Quick-Pick Pill Badges Below Trigger for 1-Click Toggling */}
            {showQuickPills && normalizedOptions.length > 0 && !disabled && (
                <div className="pt-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-[11px] font-medium text-gray-400 mr-1 select-none">
                            Quick add:
                        </span>
                        {normalizedOptions.map((opt) => {
                            const isSelected = (value || []).map(String).includes(String(opt.value));
                            return (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() => toggleOption(opt.value)}
                                    className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium transition active:scale-95 ${
                                        isSelected
                                            ? 'border border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent-soft-text)] shadow-xs'
                                            : 'border border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50'
                                    }`}
                                >
                                    {isSelected ? (
                                        <Check size={11} weight="bold" className="text-[var(--accent-soft-text)]" />
                                    ) : (
                                        <Plus size={11} weight="bold" className="text-gray-400" />
                                    )}
                                    <span>{opt.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {error && <p className="mt-1.5 text-sm text-red-600">{error}</p>}
        </div>
    );
}
