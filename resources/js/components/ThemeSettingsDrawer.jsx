import { useState } from 'react';
import { useTheme } from '../context/ThemeContext';
import { useToast } from '../context/ToastContext';
import { THEME_FIELDS, THEME_PRESETS } from '../theme';
import Button from './ui/Button';

const SCHEMES = [
    {
        value: 'light',
        label: 'Light',
        hint: 'Bright surfaces',
        swatch: 'bg-white border-gray-300',
    },
    {
        value: 'dark',
        label: 'Dark',
        hint: 'Easy on the eyes',
        swatch: 'bg-gray-900 border-gray-700',
    },
    {
        value: 'system',
        label: 'System',
        hint: 'Follow this device',
        swatch: 'bg-gradient-to-br from-white to-gray-900',
    },
];

function SchemeField({ value, onChange }) {
    return (
        <div className="grid grid-cols-3 gap-2">
            {SCHEMES.map((scheme) => {
                const active = value === scheme.value;

                return (
                    <button
                        key={scheme.value}
                        type="button"
                        onClick={() => onChange('mode', scheme.value)}
                        aria-pressed={active}
                        className={`flex flex-col items-center gap-1.5 rounded-lg border p-2.5 transition-all duration-150 hover:-translate-y-px hover:shadow-sm active:scale-95 ${
                            active
                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
                                : 'border-gray-200 text-gray-700 hover:border-gray-300 hover:bg-gray-50'
                        }`}
                    >
                        <span className={`h-5 w-9 rounded border ${scheme.swatch}`} />
                        <span className="text-xs font-semibold">{scheme.label}</span>
                        <span className="text-[10px] text-gray-500">{scheme.hint}</span>
                    </button>
                );
            })}
        </div>
    );
}

function ColorField({ field, value, onChange }) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2.5 transition-all duration-150 hover:border-gray-300 hover:shadow-sm">
            <label className="text-sm font-medium text-gray-700">{field.label}</label>
            <div className="flex items-center gap-2">
                <span className="text-xs uppercase text-gray-400">{value}</span>
                <label
                    className="relative h-8 w-10 cursor-pointer overflow-hidden rounded-md border border-gray-300 shadow-inner transition-transform duration-150 hover:scale-110 active:scale-95"
                    style={{ backgroundColor: value }}
                >
                    <input
                        type="color"
                        value={value}
                        onChange={(e) => onChange(field.key, e.target.value)}
                        className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                        aria-label={field.label}
                    />
                </label>
            </div>
        </div>
    );
}

export default function ThemeSettingsDrawer({ open, onClose }) {
    const { draft, preview, previewFull, save, reset } = useTheme();
    const toast = useToast();
    const [saving, setSaving] = useState(false);

    async function handleSave() {
        setSaving(true);
        const result = await save();
        setSaving(false);

        if (result.ok) {
            toast.success('Theme updated successfully.');
            onClose();
        } else {
            toast.error(result.message);
        }
    }

    return (
        <>
            {open && (
                <div className="fixed inset-0 z-40 animate-backdrop-in bg-black/40" onClick={onClose} aria-hidden="true" />
            )}

            <aside
                className={`fixed inset-y-0 right-0 z-50 flex w-full max-w-md transform flex-col bg-white shadow-2xl transition-transform duration-300 ease-out ${
                    open ? 'translate-x-0' : 'translate-x-full'
                }`}
                role="dialog"
                aria-label="Theme settings"
            >
                <div className="flex animate-fade-in-up items-center justify-between border-b border-gray-200 px-6 py-4">
                    <div>
                        <h2 className="text-base font-semibold text-gray-900">Theme Settings</h2>
                        <p className="text-sm text-gray-500">Switch to dark, or personalize your own colors</p>
                    </div>
                    <button
                        onClick={onClose}
                        className="rounded-lg p-2 text-gray-400 transition-all duration-150 hover:rotate-90 hover:bg-gray-100 hover:text-gray-600 active:scale-90"
                        aria-label="Close"
                    >
                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                            <path d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </button>
                </div>

                <div className="flex-1 space-y-6 animate-fade-in-up overflow-y-auto px-6 py-5" style={{ animationDelay: '40ms' }}>
                    <section>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Appearance</h3>
                        <SchemeField
                            value={draft.mode ?? 'system'}
                            onChange={preview}
                        />
                    </section>

                    <section>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Presets</h3>
                        <div className="grid grid-cols-2 gap-2">
                            {THEME_PRESETS.map((preset) => {
                                const active = THEME_FIELDS.every(
                                    (field) => preset.theme[field.key] === draft[field.key],
                                );
                                return (
                                    <button
                                        key={preset.name}
                                        onClick={() => previewFull(preset.theme)}
                                        className={`rounded-lg border p-2 text-left text-xs font-medium transition-all duration-150 hover:-translate-y-px hover:shadow-sm active:scale-95 ${
                                            active
                                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
                                                : 'border-gray-200 text-gray-700 hover:border-gray-300 hover:bg-gray-50'
                                        }`}
                                    >
                                        {preset.name}
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    <section>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Colors</h3>
                        <div className="space-y-2">
                            {THEME_FIELDS.map((field) => (
                                <ColorField
                                    key={field.key}
                                    field={field}
                                    value={draft[field.key]}
                                    onChange={preview}
                                />
                            ))}
                        </div>
                    </section>

                    <button
                        onClick={reset}
                        className="text-sm font-medium text-gray-500 underline-offset-2 transition hover:text-gray-800 hover:underline"
                    >
                        Reset to default theme
                    </button>
                </div>

                <div className="border-t border-gray-200 px-6 py-4">
                    <div className="flex gap-3">
                        <Button variant="secondary" className="flex-1" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button className="flex-1" loading={saving} onClick={handleSave}>
                            Save theme
                        </Button>
                    </div>
                </div>
            </aside>
        </>
    );
}