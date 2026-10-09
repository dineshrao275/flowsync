import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

function RecoveryCodes({ codes }) {
    return (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p className="text-sm font-medium text-amber-800">Save these recovery codes now. Each works once and they are not shown again.</p>
            <ul className="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-gray-800">
                {codes.map((c) => <li key={c}>{c}</li>)}
            </ul>
        </div>
    );
}

function TwoFactorCard({ onChanged }) {
    const toast = useToast();
    const [state, setState] = useState(null);
    const [setup, setSetup] = useState(null);
    const [codes, setCodes] = useState(null);
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const [errors, setErrors] = useState({});

    const load = useCallback(() => api.get('/auth/2fa').then(({ data }) => setState(data)), []);
    useEffect(() => { load(); }, [load]);

    async function call(request, done) {
        setErrors({});
        try {
            const { data } = await request();
            done?.(data);
            if (data.message) toast.success(data.message);
            setCode('');
            setPassword('');
            await load();
            onChanged?.();
        } catch (e) {
            setErrors(fieldErrors(e));
        }
    }

    if (!state) return <Spinner />;

    const reauth = (
        <div className="grid gap-3 sm:grid-cols-2">
            <Input label="Password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password} />
            <Input label="Current code" value={code} onChange={(e) => setCode(e.target.value)} error={errors.code} />
        </div>
    );

    return (
        <Card>
            <h2 className="text-lg font-semibold text-gray-800">Two-factor authentication</h2>
            <p className="mb-4 text-sm text-gray-500">
                Use an authenticator app (Google Authenticator, 1Password, Authy) to protect sign-in.
                {state.required && ' Your organisation requires this for your role.'}
            </p>
            <Alert>{errors.form}</Alert>

            {codes && <RecoveryCodes codes={codes} />}

            {!state.enabled && !setup && (
                <Button onClick={() => call(() => api.post('/auth/2fa/setup'), setSetup)}>Set up two-factor</Button>
            )}

            {!state.enabled && setup && (
                <div className="space-y-3">
                    <p className="text-sm text-gray-600">Add this key in your authenticator app (choose "enter a setup key", time-based), then enter the 6-digit code.</p>
                    <p className="break-all rounded bg-gray-50 p-3 font-mono text-sm">{setup.secret}</p>
                    <a className="text-xs text-[var(--accent)]" href={setup.uri}>Open in authenticator app</a>
                    <Input label="6-digit code" value={code} onChange={(e) => setCode(e.target.value)} error={errors.code} />
                    <Button onClick={() => call(() => api.post('/auth/2fa/confirm', { code }), (d) => { setCodes(d.recovery_codes); setSetup(null); })}>
                        Confirm and enable
                    </Button>
                </div>
            )}

            {state.enabled && (
                <div className="space-y-4">
                    <p className="text-sm text-green-700">Enabled. {state.recovery_codes_remaining} recovery codes left.</p>
                    {reauth}
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" onClick={() => call(() => api.post('/auth/2fa/recovery-codes', { password, code }), (d) => setCodes(d.recovery_codes))}>
                            New recovery codes
                        </Button>
                        {!state.required && (
                            <Button variant="danger" onClick={() => call(() => api.post('/auth/2fa/disable', { password, code }), () => setCodes(null))}>
                                Turn off
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </Card>
    );
}

function TenantPolicyCard() {
    const toast = useToast();
    const [policy, setPolicy] = useState(null);

    useEffect(() => { api.get('/security/two-factor-policy').then(({ data }) => setPolicy(data)).catch(() => setPolicy(false)); }, []);

    if (policy === null) return <Spinner />;
    if (policy === false) return null;

    async function toggle(slug, on) {
        const roles = on ? [...policy.roles, slug] : policy.roles.filter((r) => r !== slug);
        try {
            const { data } = await api.put('/security/two-factor-policy', { roles });
            setPolicy(data);
            toast.success(data.message);
        } catch (e) {
            toast.error(fieldErrors(e).roles || 'Could not save the policy.');
        }
    }

    return (
        <Card>
            <h2 className="text-lg font-semibold text-gray-800">Require two-factor by role</h2>
            <p className="mb-3 text-sm text-gray-500">People in a checked role must set up two-factor at their next sign-in.</p>
            <div className="grid gap-2 sm:grid-cols-2">
                {policy.available_roles.map((r) => (
                    <label key={r.slug} className="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={policy.roles.includes(r.slug)} onChange={(e) => toggle(r.slug, e.target.checked)} />
                        {r.name}
                    </label>
                ))}
            </div>
        </Card>
    );
}

function PlatformPolicyCard() {
    const toast = useToast();
    const [on, setOn] = useState(null);

    useEffect(() => { api.get('/system/two-factor-policy').then(({ data }) => setOn(data.require_super_admins)); }, []);

    if (on === null) return <Spinner />;

    async function change(value) {
        const { data } = await api.put('/system/two-factor-policy', { require_super_admins: value });
        setOn(data.require_super_admins);
        toast.success(data.message);
    }

    return (
        <Card>
            <h2 className="text-lg font-semibold text-gray-800">Platform administrators</h2>
            <label className="mt-2 flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" checked={on} onChange={(e) => change(e.target.checked)} />
                Require two-factor for every platform admin
            </label>
        </Card>
    );
}

export default function Security() {
    usePageTitle('Security');
    useSetCrumbs([{ label: 'Security' }]);
    const { user, can, refresh } = useAuth();
    const platform = user.is_super_admin && !user.impersonating;

    return (
        <div className="max-w-3xl space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">Security</h1>
                {user.two_factor?.enrollment_required && (
                    <p className="mt-1 text-sm text-red-600">Set up two-factor authentication to continue using the app.</p>
                )}
            </div>
            <TwoFactorCard onChanged={refresh} />
            {platform ? <PlatformPolicyCard /> : can('roles.manage') && <TenantPolicyCard />}
        </div>
    );
}
