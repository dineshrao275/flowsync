import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import Switch from '../components/ui/Switch';
import { fieldClassCompact } from '../components/ui/fieldStyles';
import ApiTokens from '../components/settings/ApiTokens';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';

function eventLabel(t, event) {
    const [scope, name] = event.split('.');
    return t(`admin.settings.events.${scope}.${name}`, { defaultValue: event });
}

function NotificationPreferences() {
    const { t } = useTranslation();
    const toast = useToast();
    const [prefs, setPrefs] = useState(undefined);
    const [emailMode, setEmailMode] = useState('instant');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api.get('/notification-preferences')
            .then(({ data }) => {
                setPrefs(data.preferences);
                setEmailMode(data.email_mode ?? 'instant');
            })
            .catch(() => setPrefs(null));
    }, []);

    function changeMode(next) {
        const last = emailMode;
        setEmailMode(next);
        api.put('/notification-preferences', { email_mode: next })
            .then(() => toast.success(t('admin.settings.prefsSaved')))
            .catch(() => {
                setEmailMode(last);
                toast.error(t('admin.settings.prefsSaveFailed'));
            });
    }

    function toggle(event) {
        if (!prefs || saving) return;

        const last = prefs[event];
        setPrefs({ ...prefs, [event]: !last });
        setSaving(true);
        api.put('/notification-preferences', { preferences: { ...prefs, [event]: !last } })
            .then(() => toast.success(t('admin.settings.prefsSaved')))
            .catch(() => {
                setPrefs({ ...prefs, [event]: last });
                toast.error(t('admin.settings.prefsSaveFailed'));
            })
            .finally(() => setSaving(false));
    }

    if (prefs === undefined) {
        return (
            <div className="flex justify-center py-8">
                <Spinner />
            </div>
        );
    }

    if (prefs === null) {
        return (
            <p className="py-8 text-sm text-gray-500">
                {t('admin.settings.prefsUnavailable')}
            </p>
        );
    }

    return (
        <div>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-50 px-4 py-3">
                <div>
                    <label htmlFor="email-mode" className="text-sm font-medium text-gray-800">{t('admin.settings.emailModeLabel')}</label>
                    <p className="text-xs text-gray-500">{t('admin.settings.emailModeHint')}</p>
                </div>
                <select id="email-mode" className={`${fieldClassCompact} w-auto`} value={emailMode} onChange={(e) => changeMode(e.target.value)}>
                    <option value="instant">{t('admin.settings.emailModeInstant')}</option>
                    <option value="digest">{t('admin.settings.emailModeDigest')}</option>
                </select>
            </div>
        <ul className="divide-y divide-gray-100">
            {Object.keys(prefs).map((event) => (
                <li key={event} className="flex items-center justify-between gap-4 py-3">
                    <div>
                        <p className="text-sm font-medium text-gray-800">
                            {eventLabel(t, event)}
                        </p>
                        <p className="text-xs text-gray-400">{t('admin.settings.emailMeWhen')}</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={prefs[event]}
                        aria-label={eventLabel(t, event)}
                        onClick={() => toggle(event)}
                        disabled={saving}
                        className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${
                            prefs[event] ? 'bg-[var(--accent)]' : 'bg-gray-300'
                        } disabled:opacity-60`}
                    >
                        <span
                            className={`absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform ${
                                prefs[event] ? 'translate-x-5' : ''
                            }`}
                        />
                    </button>
                </li>
            ))}
        </ul>
        </div>
    );
}

function TenantEmailPolicy() {
    const { t } = useTranslation();
    const toast = useToast();
    const [policies, setPolicies] = useState(undefined);

    useEffect(() => {
        api.get('/notification-policies')
            .then(({ data }) => setPolicies(data.policies))
            .catch(() => setPolicies(null));
    }, []);

    function toggle(event, enabled) {
        const last = policies[event];
        setPolicies({ ...policies, [event]: enabled });
        api.put('/notification-policies', { policies: { [event]: enabled } })
            .then(() => toast.success(t('admin.settings.policySaved')))
            .catch(() => {
                setPolicies({ ...policies, [event]: last });
                toast.error(t('admin.settings.policySaveFailed'));
            });
    }

    if (policies === undefined) return <div className="flex justify-center py-8"><Spinner /></div>;
    if (policies === null) return <p className="py-8 text-sm text-gray-500">{t('admin.settings.prefsUnavailable')}</p>;

    return (
        <ul className="divide-y divide-gray-100">
            {Object.keys(policies).map((event) => (
                <li key={event} className="py-3">
                    <Switch
                        label={eventLabel(t, event)}
                        description={t('admin.settings.policyEventHint')}
                        checked={policies[event]}
                        onChange={(v) => toggle(event, v)}
                    />
                </li>
            ))}
        </ul>
    );
}

export default function Settings() {
    const { t } = useTranslation();
    usePageTitle(t('admin.settings.pageTitle'));
    const { user, can, hasModule } = useAuth();

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('admin.settings.pageTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('admin.settings.subtitle')}</p>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="animate-fade-in-up">
                    <Card title={t('admin.settings.profileTitle')} subtitle={t('admin.settings.profileSubtitle')}>
                    <dl className="space-y-4">
                        <div>
                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('admin.common.name')}</dt>
                            <dd className="mt-1 text-sm font-medium text-gray-800">{user?.name}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('admin.common.email')}</dt>
                            <dd className="mt-1 text-sm font-medium text-gray-800">{user?.email}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('admin.users.roles')}</dt>
                            <dd className="mt-1 flex flex-wrap gap-1">
                                {(user?.roles || []).map((role) => (
                                    <span
                                        key={role}
                                        className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium capitalize text-gray-700"
                                    >
                                        {role}
                                    </span>
                                ))}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('admin.roles.permissions')}</dt>
                            <dd className="mt-1 flex flex-wrap gap-1">
                                {(user?.permissions || []).map((permission) => (
                                    <span
                                        key={permission}
                                        className="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600"
                                    >
                                        {permission}
                                    </span>
                                ))}
                            </dd>
                        </div>
                    </dl>
                </Card>
                </div>

                <div className="animate-fade-in-up" style={{ animationDelay: '100ms' }}>
                <Card title={t('admin.settings.appearanceTitle')} subtitle={t('admin.settings.appearanceSubtitle')}>
                    <p className="text-sm text-gray-600">
                        {t('admin.settings.appearanceBody')}
                    </p>
                    <div className="mt-4 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500">
                        {t('admin.settings.appearanceHint')}
                    </div>
                </Card>
                </div>
            </div>

            <div className="animate-fade-in-up" style={{ animationDelay: '200ms' }}>
                <Card title={t('admin.settings.notificationsTitle')} subtitle={t('admin.settings.notificationsSubtitle')}>
                    <NotificationPreferences />
                </Card>
            </div>

            {can('tenant.manage') && (
                <div className="animate-fade-in-up" style={{ animationDelay: '250ms' }}>
                    <Card title={t('admin.settings.policyTitle')} subtitle={t('admin.settings.policySubtitle')}>
                        <TenantEmailPolicy />
                    </Card>
                </div>
            )}

            {can('api.manage') && hasModule('api') && (
                <div className="animate-fade-in-up" style={{ animationDelay: '300ms' }}>
                    <Card title={t('admin.settings.apiTokensTitle')} subtitle={t('admin.settings.apiTokensSubtitle')}>
                        <ApiTokens />
                    </Card>
                </div>
            )}
        </div>
    );
}