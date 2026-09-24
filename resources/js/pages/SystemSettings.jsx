import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

function Toggle({ label, hint, checked, onChange, disabled }) {
    return (
        <label className={`flex items-center justify-between gap-4 ${disabled ? 'opacity-60' : ''}`}>
            <span>
                <span className="block text-sm font-medium text-gray-800">{label}</span>
                {hint && <span className="block text-xs text-gray-400">{hint}</span>}
            </span>
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(e) => onChange(e.target.checked)}
                className="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
        </label>
    );
}

export default function SystemSettings() {
    usePageTitle('System Settings');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Settings' }]);
    const { toast } = useToast();
    const [settings, setSettings] = useState(null);
    const [plans, setPlans] = useState([]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        api.get('/system/settings').then(({ data }) => setSettings(data.settings)).catch(() => {});
        api.get('/plans').then(({ data }) => setPlans(data.plans)).catch(() => {});
    }, []);

    function set(key, value) {
        setSettings((s) => ({ ...s, [key]: value }));
        setSaved(false);
    }

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const { data } = await api.put('/system/settings', settings);
            setSettings(data.settings);
            setSaved(true);
            toast.success('Settings saved.');
        } catch (e) {
            setErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    if (!settings) return <Spinner />;

    return (
        <div className="max-w-2xl space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">System Settings</h1>
                <p className="text-sm text-gray-500">Platform-wide configuration (central system database).</p>
            </div>

            <Card>
                <form onSubmit={submit} className="space-y-6">
                    <Input
                        label="App name"
                        name="app_name"
                        value={settings.app_name}
                        onChange={(e) => set('app_name', e.target.value)}
                        error={errors.app_name}
                    />

                    <div className="space-y-4">
                        <Toggle
                            label="Public registration"
                            hint="Allow anyone to self-register a workspace at /register."
                            checked={!!settings.public_registration}
                            onChange={(v) => set('public_registration', v)}
                        />
                        <Toggle
                            label="Maintenance mode"
                            hint="Platform-wide maintenance flag (stored; consumers read it as needed)."
                            checked={!!settings.maintenance_mode}
                            onChange={(v) => set('maintenance_mode', v)}
                        />
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Default plan for new tenants</label>
                        <select
                            value={settings.default_plan_id || ''}
                            onChange={(e) => set('default_plan_id', e.target.value ? Number(e.target.value) : null)}
                            className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                        >
                            <option value="">None (first-come uses the plan’s own default)</option>
                            {plans.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name} ({p.slug})
                                </option>
                            ))}
                        </select>
                        {errors.default_plan_id && <p className="mt-1 text-xs text-red-600">{errors.default_plan_id}</p>}
                    </div>

                    {saved && <p className="text-sm font-medium text-green-600">Saved.</p>}

                    <div className="flex justify-end">
                        <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save settings'}</Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}