import { useState } from 'react';
import Input from '../ui/Input';

const GIB = 1024 ** 3;

/**
 * Numeric plan quotas (`config/subscriptions.php` → `limits`). A blank field
 * means "unlimited": TenantLimits reads a missing/null cap as no limit, so the
 * editor strips blanks instead of persisting `""` or 0.
 *
 * Mount with a `key` that changes when the edited record changes so the local
 * state re-initializes (both call sites render one editor per plan/tenant).
 */
const FIELDS = [
    { key: 'users', label: 'Users', hint: 'User accounts in the tenant' },
    { key: 'seats', label: 'Seats', hint: 'Seat-based alias of users' },
    { key: 'workspaces', label: 'Workspaces' },
    { key: 'projects', label: 'Projects' },
    { key: 'tasks', label: 'Tasks' },
    { key: 'storage_bytes', label: 'Storage (GB)', bytes: true },
    { key: 'attachments_per_task', label: 'Attachments / task' },
];

function toFormValues(limits) {
    const values = {};

    for (const field of FIELDS) {
        const raw = limits?.[field.key];
        values[field.key] = raw === null || raw === undefined || raw === '' ? '' : field.bytes ? Math.round((raw / GIB) * 100) / 100 : raw;
    }

    return values;
}

export function formToLimits(values) {
    const limits = {};

    for (const field of FIELDS) {
        const raw = values[field.key];

        if (raw === '' || raw === null || raw === undefined) continue;

        const numeric = Number(raw);

        if (!Number.isFinite(numeric) || numeric < 0) continue;

        limits[field.key] = field.bytes ? Math.round(numeric * GIB) : Math.round(numeric);
    }

    return Object.keys(limits).length ? limits : null;
}

export function limitValue(limits, key) {
    const raw = limits?.[key];

    return raw === null || raw === undefined ? null : raw;
}

export function formatBytes(bytes) {
    if (bytes === null) return '∞';

    if (bytes >= GIB) return `${Math.round((bytes / GIB) * 100) / 100} GB`;

    return `${Math.round(bytes / 1024 / 1024)} MB`;
}

export default function LimitsEditor({ limits, errors = {}, onChange, legend = 'Resource limits', hint = 'Blank = unlimited. Caps are enforced on create, and a tenant override wins over its plan.' }) {
    const [values, setValues] = useState(() => toFormValues(limits));

    function set(key, value) {
        const next = { ...values, [key]: value };
        setValues(next);
        onChange(formToLimits(next));
    }

    return (
        <fieldset className="rounded-lg border border-gray-200 p-4">
            <legend className="px-1 text-sm font-medium text-gray-700">{legend}</legend>
            <p className="mb-3 text-xs text-gray-500">{hint}</p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {FIELDS.map((field) => (
                    <Input
                        key={field.key}
                        label={field.label}
                        type="number"
                        min="0"
                        name={`limits.${field.key}`}
                        value={values[field.key]}
                        onChange={(e) => set(field.key, e.target.value)}
                        error={errors[`limits.${field.key}`] ?? errors[`limits_override.${field.key}`]}
                        placeholder="∞"
                    />
                ))}
            </div>
            <p className="mt-3 text-xs text-gray-500">
                <span className="font-medium text-gray-600">Seats</span> is a seat-based alias of users; both are capped
                by whichever value is set.
            </p>
        </fieldset>
    );
}
