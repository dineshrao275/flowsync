import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import Input from '../ui/Input';
import Switch from '../ui/Switch';
import { fieldClass } from '../ui/fieldStyles';

/** Definitions of one entity's custom fields (empty when the plan lacks the module or the call fails). */
export function useCustomFieldDefinitions(entity) {
    const { hasModule } = useAuth();
    const [fields, setFields] = useState([]);
    const enabled = hasModule('custom_fields');

    useEffect(() => {
        if (!enabled) return undefined;
        let active = true;
        api.get('/custom-fields', { params: { entity } })
            .then(({ data }) => active && setFields(data.fields))
            .catch(() => active && setFields([]));

        return () => {
            active = false;
        };
    }, [entity, enabled]);

    return enabled ? fields : [];
}

/**
 * One input per custom field. `values` is the { key: value } map and `onChange(next)` receives the
 * whole new map; empty strings are sent as-is (the server clears the value). `errors` is the
 * Laravel field-error map, whose keys look like `custom_fields.<key>`.
 */
export default function CustomFieldInputs({ fields, values, onChange, errors = {} }) {
    const { t } = useTranslation();
    if (!fields.length) return null;

    const set = (key, value) => onChange({ ...values, [key]: value });

    return (
        <fieldset className="space-y-3 rounded-lg border border-gray-200 p-4">
            <legend className="px-1 text-sm font-semibold text-gray-700">{t('admin.customFields.sectionTitle')}</legend>
            {fields.map((f) => {
                const error = errors[`custom_fields.${f.key}`];
                const id = `cf-${f.key}`;
                const value = values[f.key] ?? (f.type === 'multiselect' ? [] : '');
                const label = f.required ? `${f.label} *` : f.label;

                if (f.type === 'boolean') {
                    return <Switch key={f.key} id={id} label={label} description={f.help_text} checked={value === true || value === 'true'} onChange={(v) => set(f.key, v)} />;
                }

                if (f.type === 'select') {
                    return (
                        <div key={f.key}>
                            <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-gray-700">{label}</label>
                            <select id={id} className={fieldClass} value={value} onChange={(e) => set(f.key, e.target.value)}>
                                <option value="">{t('admin.customFields.none')}</option>
                                {f.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                            {(error || f.help_text) && <p className={`mt-1 text-xs ${error ? 'text-red-600' : 'text-gray-500'}`}>{error || f.help_text}</p>}
                        </div>
                    );
                }

                if (f.type === 'multiselect') {
                    return (
                        <fieldset key={f.key}>
                            <legend className="mb-1.5 text-sm font-medium text-gray-700">{label}</legend>
                            <div className="flex flex-wrap gap-3">
                                {f.options.map((o) => (
                                    <label key={o.value} className="flex items-center gap-1.5 text-sm text-gray-700">
                                        <input
                                            type="checkbox"
                                            checked={value.includes(o.value)}
                                            onChange={(e) => set(f.key, e.target.checked ? [...value, o.value] : value.filter((x) => x !== o.value))}
                                        />
                                        {o.label}
                                    </label>
                                ))}
                            </div>
                            {(error || f.help_text) && <p className={`mt-1 text-xs ${error ? 'text-red-600' : 'text-gray-500'}`}>{error || f.help_text}</p>}
                        </fieldset>
                    );
                }

                const type = { number: 'number', currency: 'number', date: 'date', datetime: 'datetime-local', email: 'email', url: 'url' }[f.type] || 'text';

                return (
                    <div key={f.key}>
                    <Input
                        id={id}
                        label={label}
                        type={type}
                        step={f.type === 'currency' ? '0.01' : undefined}
                        value={value}
                        onChange={(e) => set(f.key, e.target.value)}
                        error={error}
                    />
                    {f.help_text && !error && <p className="mt-1 text-xs text-gray-500">{f.help_text}</p>}
                    </div>
                );
            })}
        </fieldset>
    );
}

/** Read-only rows of a record's custom-field values (hidden when there is nothing to show). */
export function CustomFieldValues({ entity, values, Row }) {
    const fields = useCustomFieldDefinitions(entity);
    const shown = fields.filter((f) => values && values[f.key] !== undefined && values[f.key] !== null && values[f.key] !== '');

    if (!shown.length) return null;

    const show = (f) => {
        const v = values[f.key];
        if (f.type === 'boolean') return v ? '✓' : '—';
        if (f.type === 'select') return f.options.find((o) => o.value === v)?.label ?? v;
        if (f.type === 'multiselect') return v.map((x) => f.options.find((o) => o.value === x)?.label ?? x).join(', ');

        return String(v);
    };

    return shown.map((f) => <Row key={f.key} label={f.label} value={show(f)} />);
}
