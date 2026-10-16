import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Switch from '../ui/Switch';

/** Turns the project's workflow, components and (optionally) open tasks into a reusable template. */
export default function SaveAsTemplate({ projectId, defaultName }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [name, setName] = useState(defaultName ? `${defaultName} template` : '');
    const [includeTasks, setIncludeTasks] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setError(null);
        try {
            await api.post(`/projects/${projectId}/save-as-template`, { name: name.trim(), include_tasks: includeTasks });
            toast.success(t('tms.templates.saved'));
        } catch (err) {
            setError(fieldErrors(err).name || t('tms.templates.saveFailed'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={save} className="mt-8 space-y-4 border-t border-gray-100 pt-6">
            <div>
                <h4 className="text-sm font-semibold text-gray-900">{t('tms.templates.title')}</h4>
                <p className="mt-0.5 text-sm text-gray-500">{t('tms.templates.hint')}</p>
            </div>
            <Input label={t('tms.templates.name')} value={name} onChange={(e) => setName(e.target.value)} error={error} required maxLength={120} />
            <Switch label={t('tms.templates.includeTasks')} description={t('tms.templates.includeTasksHint')} checked={includeTasks} onChange={setIncludeTasks} />
            <Button type="submit" variant="secondary" loading={saving} disabled={!name.trim()}>{t('tms.templates.save')}</Button>
        </form>
    );
}
