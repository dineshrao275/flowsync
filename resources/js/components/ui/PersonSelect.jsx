import { useId } from 'react';
import MemberPicker from './MemberPicker';

/**
 * Drop-in replacement for a `<Select>` that picks one person: same `label` / `error` / `required` /
 * `value` / `onChange(event)` contract (the handler receives `{ target: { value } }` with the id as a
 * string, '' when cleared) but the choices are `people` — `[{id, name, email?, photo_url?}]` — shown as
 * avatar cards with search. Existing call sites keep their handlers unchanged.
 */
export default function PersonSelect({ label, error, id, className = '', people, value, onChange, placeholder, required, disabled }) {
    const autoId = useId();
    const fieldId = id || autoId;

    return (
        <div className={`relative ${className}`}>
            {label && <label htmlFor={fieldId} className="mb-1.5 block text-sm font-medium text-gray-700">{label}</label>}
            <MemberPicker
                id={fieldId}
                users={people ?? []}
                value={value ?? ''}
                disabled={disabled}
                placeholder={placeholder}
                onChange={(next) => onChange?.({ target: { value: next === '' ? '' : String(next) } })}
            />
            {required && (
                <input
                    tabIndex={-1}
                    aria-hidden="true"
                    required
                    value={value ?? ''}
                    onChange={() => {}}
                    className="pointer-events-none absolute h-0 w-0 opacity-0"
                />
            )}
            {error && <p className="mt-1.5 text-sm text-red-600">{error}</p>}
        </div>
    );
}
