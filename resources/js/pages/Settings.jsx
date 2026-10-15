import { useEffect, useState } from 'react';
import api from '../services/api';
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import ApiTokens from '../components/settings/ApiTokens';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';

const EVENT_LABELS = {
    'task.assigned': 'Task assigned to you',
    'task.status_changed': 'A task you work on changes status',
    'task.commented': 'Someone comments on or mentions your task',
    'task.unblocked': 'A blocker on your task is removed',
    'task.work_logged': 'Time is logged against your task',
};

function NotificationPreferences() {
    const toast = useToast();
    const [prefs, setPrefs] = useState(undefined);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api.get('/notification-preferences')
            .then(({ data }) => setPrefs(data.preferences))
            .catch(() => setPrefs(null));
    }, []);

    function toggle(event) {
        if (!prefs || saving) return;

        const last = prefs[event];
        setPrefs({ ...prefs, [event]: !last });
        setSaving(true);
        api.put('/notification-preferences', { preferences: { ...prefs, [event]: !last } })
            .then(() => toast.success('Notification preferences saved.'))
            .catch(() => {
                setPrefs({ ...prefs, [event]: last });
                toast.error('Could not save your preferences.');
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
                Notification preferences are unavailable right now.
            </p>
        );
    }

    return (
        <ul className="divide-y divide-gray-100">
            {Object.keys(prefs).map((event) => (
                <li key={event} className="flex items-center justify-between gap-4 py-3">
                    <div>
                        <p className="text-sm font-medium text-gray-800">
                            {EVENT_LABELS[event] ?? event}
                        </p>
                        <p className="text-xs text-gray-400">Email me when this happens</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={prefs[event]}
                        aria-label={EVENT_LABELS[event] ?? event}
                        onClick={() => toggle(event)}
                        disabled={saving}
                        className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${
                            prefs[event] ? 'bg-indigo-600' : 'bg-gray-300'
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
    );
}

export default function Settings() {
    usePageTitle('Settings');
    const { user, can, hasModule } = useAuth();

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Settings</h2>
                <p className="mt-1 text-sm text-gray-500">Manage your account and preferences.</p>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="animate-fade-in-up">
                    <Card title="Profile" subtitle="Your account details">
                    <dl className="space-y-4">
                        <div>
                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">Name</dt>
                            <dd className="mt-1 text-sm font-medium text-gray-800">{user?.name}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">Email</dt>
                            <dd className="mt-1 text-sm font-medium text-gray-800">{user?.email}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">Roles</dt>
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
                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">Permissions</dt>
                            <dd className="mt-1 flex flex-wrap gap-1">
                                {(user?.permissions || []).map((permission) => (
                                    <span
                                        key={permission}
                                        className="rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600"
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
                <Card title="Appearance" subtitle="Theme customization is per-account">
                    <p className="text-sm text-gray-600">
                        Your saved theme is stored against your account and reapplied automatically on
                        every sign-in. Other admins each keep their own theme, so changes you make here
                        never affect anyone else.
                    </p>
                    <div className="mt-4 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500">
                        Click the palette icon in the top bar to open the theme settings panel.
                    </div>
                </Card>
                </div>
            </div>

            <div className="animate-fade-in-up" style={{ animationDelay: '200ms' }}>
                <Card title="Notifications" subtitle="Choose which task emails are delivered to you. In-app notifications are always shown.">
                    <NotificationPreferences />
                </Card>
            </div>

            {can('api.manage') && hasModule('api') && (
                <div className="animate-fade-in-up" style={{ animationDelay: '300ms' }}>
                    <Card title="API tokens" subtitle="Personal access tokens for scripts and integrations.">
                        <ApiTokens />
                    </Card>
                </div>
            )}
        </div>
    );
}