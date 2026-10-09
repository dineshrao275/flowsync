import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

const emptyForm = { key: '', description: '' };

export default function FeatureFlags() {
    usePageTitle('Feature Flags');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Feature flags' }]);
    const toast = useToast();
    const [flags, setFlags] = useState(null);
    const [tenants, setTenants] = useState([]);
    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});
    const [overrideTenant, setOverrideTenant] = useState({});

    const load = useCallback(() => {
        api.get('/system/feature-flags').then(({ data }) => setFlags(data.flags)).catch(() => setFlags([]));
    }, []);

    useEffect(() => {
        load();
        api.get('/tenants', { params: { per_page: 100 } })
            .then(({ data }) => setTenants(data.tenants || []))
            .catch(() => {});
    }, [load]);

    const replace = (flag) => setFlags((list) => list.map((f) => (f.id === flag.id ? flag : f)));

    async function run(promise, after) {
        try {
            const { data } = await promise;
            if (data.flag) replace(data.flag);
            if (after) after(data);
            toast.success(data.message);
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Request failed.');
        }
    }

    async function create(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post('/system/feature-flags', form);
            setForm(emptyForm);
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    if (flags === null) return <Spinner />;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">Feature flags</h1>
                <p className="text-sm text-gray-500">
                    Runtime switches with percentage rollout. A tenant override always beats the global switch; unknown flags are off.
                </p>
            </div>

            <Card>
                <form onSubmit={create} className="grid gap-3 md:grid-cols-3">
                    <Input label="Key" value={form.key} error={errors.key} placeholder="new_board"
                        onChange={(e) => setForm({ ...form, key: e.target.value })} />
                    <Input label="Description" value={form.description} error={errors.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })} />
                    <div className="flex items-end"><Button type="submit">Add flag</Button></div>
                </form>
            </Card>

            {flags.length === 0 && <p className="text-sm text-gray-400">No flags yet.</p>}

            {flags.map((flag) => (
                <Card key={flag.id}>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="font-mono text-sm font-semibold text-gray-800">{flag.key}</p>
                            <p className="text-xs text-gray-500">{flag.description || 'No description'}</p>
                        </div>
                        <div className="flex items-center gap-3">
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" checked={flag.enabled}
                                    onChange={(e) => run(api.put(`/system/feature-flags/${flag.id}`, { enabled: e.target.checked }))} />
                                Enabled
                            </label>
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                Rollout
                                <input type="number" min="0" max="100" defaultValue={flag.rollout_percent}
                                    className="w-16 rounded-lg border border-gray-200 px-2 py-1 text-sm"
                                    onBlur={(e) => Number(e.target.value) !== flag.rollout_percent
                                        && run(api.put(`/system/feature-flags/${flag.id}`, { rollout_percent: Number(e.target.value) }))} />
                                %
                            </label>
                            <Button variant="danger" onClick={() => run(api.delete(`/system/feature-flags/${flag.id}`), load)}>Delete</Button>
                        </div>
                    </div>

                    <div className="mt-4 border-t border-gray-100 pt-3">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Tenant overrides</p>
                        <div className="flex flex-wrap gap-2">
                            {flag.overrides.map((o) => (
                                <Badge key={o.tenant_id}>
                                    {o.tenant?.name || o.tenant_id}: {o.enabled ? 'on' : 'off'}
                                    <button type="button" className="ml-2 text-gray-400"
                                        onClick={() => run(api.delete(`/system/feature-flags/${flag.id}/overrides/${o.tenant_id}`))}>x</button>
                                </Badge>
                            ))}
                        </div>
                        <div className="mt-2 flex gap-2">
                            <select className="rounded-lg border border-gray-200 px-2 py-1 text-sm"
                                value={overrideTenant[flag.id] || ''}
                                onChange={(e) => setOverrideTenant({ ...overrideTenant, [flag.id]: e.target.value })}>
                                <option value="">Select tenant...</option>
                                {tenants.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </select>
                            {[true, false].map((on) => (
                                <Button key={String(on)} variant="secondary" disabled={!overrideTenant[flag.id]}
                                    onClick={() => run(api.put(`/system/feature-flags/${flag.id}/overrides/${overrideTenant[flag.id]}`, { enabled: on }))}>
                                    Force {on ? 'on' : 'off'}
                                </Button>
                            ))}
                        </div>
                    </div>
                </Card>
            ))}
        </div>
    );
}
