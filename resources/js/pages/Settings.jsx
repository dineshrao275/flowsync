import { useEffect, useState } from 'react';
import api from '../services/api';
import Spinner from '../components/ui/Spinner';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';

const INTEGRATIONS_LIST = [
    {
        id: 'user_sync',
        name: 'User synchronization',
        description: 'Employee accounts, names, email and active status',
        color: '#4B5EF5',
        icon: '👤',
        enabled: true,
    },
    {
        id: 'org_structure',
        name: 'Organization structure',
        description: 'Departments, reporting managers and team membership',
        color: '#1F9B69',
        icon: '⌘',
        enabled: true,
    },
    {
        id: 'project_membership',
        name: 'Project team membership',
        description: 'Assign HRMS employees as TMS project members',
        color: '#7B61FF',
        icon: '⊞',
        enabled: true,
    },
    {
        id: 'leave_availability',
        name: 'Leave & availability',
        description: 'Reflect approved leave in workload and sprint capacity',
        color: '#00A884',
        icon: '🕒',
        enabled: true,
    },
    {
        id: 'time_tracking',
        name: 'Time tracking summaries',
        description: 'Share approved work-log totals for workforce reporting',
        color: '#DA972E',
        icon: '⏱',
        enabled: false,
    },
    {
        id: 'sso_permissions',
        name: 'SSO & shared permissions',
        description: 'Unified identity and centrally managed access policies',
        color: '#4B5EF5',
        icon: '✓',
        enabled: true,
    },
];

const SYNC_STATUS_ITEMS = [
    { label: 'Users', value: '360 synced', time: '2 min ago', status: 'healthy' },
    { label: 'Departments', value: '18 synced', time: '2 min ago', status: 'healthy' },
    { label: 'Projects', value: '24 linked', time: '5 min ago', status: 'healthy' },
    { label: 'Leave calendar', value: 'Last sync OK', time: '7 min ago', status: 'healthy' },
    { label: 'Work logs', value: 'Paused by policy', time: '1 day ago', status: 'warning' },
];

const FIELD_MAPPINGS = [
    { source: 'Employee ID', target: 'User ID' },
    { source: 'Department code', target: 'Team / project role' },
    { source: 'Manager ID', target: 'Team lead' },
    { source: 'Leave status', target: 'Capacity calendar' },
];

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
        return <p className="py-8 text-sm text-[#8C96A8]">Notification preferences are unavailable right now.</p>;
    }

    return (
        <ul className="divide-y divide-[#F0F2F7]">
            {Object.keys(prefs).map((event) => {
                const isEnabled = prefs[event];
                return (
                    <li key={event} className="flex items-center justify-between gap-4 py-3">
                        <div>
                            <p className="text-[13px] font-medium text-[#171C2C]">{EVENT_LABELS[event] ?? event}</p>
                            <p className="text-[12px] text-[#8C96A8]">Email me when this happens</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={isEnabled}
                            onClick={() => toggle(event)}
                            disabled={saving}
                            className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${
                                isEnabled ? 'bg-[#4B5EF5]' : 'bg-[#E5E8F0]'
                            } disabled:opacity-60`}
                        >
                            <span
                                className={`absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow-xs transition-transform ${
                                    isEnabled ? 'translate-x-5' : ''
                                }`}
                            />
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}

export default function Settings() {
    usePageTitle('Connected apps & integrations');
    const { user } = useAuth();
    const toast = useToast();

    const [activeTab, setActiveTab] = useState('integrations');
    const [toggles, setToggles] = useState(
        INTEGRATIONS_LIST.reduce((acc, curr) => ({ ...acc, [curr.id]: curr.enabled }), {}),
    );

    function toggleIntegration(id) {
        const next = !toggles[id];
        setToggles((prev) => ({ ...prev, [id]: next }));
        toast.success(`Sync for ${id.replace(/_/g, ' ')} ${next ? 'enabled' : 'paused'}.`);
    }

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">
                        Connected apps & integrations
                    </h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Keep HRMS and TMS separate, connected by shared identity, scoped permissions and event-based sync.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-[#E6F7EF] px-3 py-1 text-[12px] font-semibold text-[#1F9B69]">
                        <span className="h-1.5 w-1.5 rounded-full bg-[#1F9B69]" />
                        Sync healthy
                    </span>
                </div>
            </div>

            {/* Pill Tabs */}
            <div className="flex items-center gap-2 border-b border-[#E5E8F0] pb-2">
                {[
                    { id: 'integrations', label: 'Connected apps & sync' },
                    { id: 'profile', label: 'Profile & account' },
                    { id: 'notifications', label: 'Notification preferences' },
                ].map((tab) => (
                    <button
                        key={tab.id}
                        type="button"
                        onClick={() => setActiveTab(tab.id)}
                        className={`rounded-lg px-3.5 py-1.5 text-[13px] font-medium transition-colors ${
                            activeTab === tab.id
                                ? 'bg-[#171C2C] text-white shadow-xs'
                                : 'text-[#5A6478] hover:bg-[#F4F6FB] hover:text-[#171C2C]'
                        }`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {/* Tab 1: Integrations (Figma Screen 18 1:1 Parity) */}
            {activeTab === 'integrations' && (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    {/* Left: HRMS ↔ TMS connection toggles */}
                    <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-8">
                        <div className="mb-6">
                            <h2 className="text-[16px] font-semibold text-[#171C2C]">HRMS ↔ TMS connection</h2>
                            <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                                Control which shared capabilities synchronize between the two apps.
                            </p>
                        </div>

                        <div className="divide-y divide-[#F0F2F7]">
                            {INTEGRATIONS_LIST.map((item) => {
                                const isOn = !!toggles[item.id];
                                return (
                                    <div key={item.id} className="flex items-center justify-between py-4 first:pt-1 last:pb-1">
                                        <div className="flex items-center gap-3.5">
                                            <div
                                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-[16px] font-bold text-white shadow-xs"
                                                style={{ backgroundColor: item.color }}
                                            >
                                                {item.icon}
                                            </div>
                                            <div>
                                                <h3 className="text-[14px] font-semibold text-[#171C2C]">{item.name}</h3>
                                                <p className="mt-0.5 text-[12px] text-[#8C96A8]">{item.description}</p>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={isOn}
                                            onClick={() => toggleIntegration(item.id)}
                                            className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${
                                                isOn ? 'bg-[#4B5EF5]' : 'bg-[#E5E8F0]'
                                            }`}
                                        >
                                            <span
                                                className={`absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow-xs transition-transform ${
                                                    isOn ? 'translate-x-5' : ''
                                                }`}
                                            />
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {/* Right: Sync status & Field mapping */}
                    <div className="space-y-6 lg:col-span-4">
                        {/* Sync status Card */}
                        <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                            <div className="mb-4">
                                <h2 className="text-[16px] font-semibold text-[#171C2C]">Sync status</h2>
                                <p className="mt-0.5 text-[12px] text-[#8C96A8]">Last successful event processing</p>
                            </div>

                            <div className="divide-y divide-[#F0F2F7]">
                                {SYNC_STATUS_ITEMS.map((item) => (
                                    <div key={item.label} className="flex items-center justify-between py-3 first:pt-1 last:pb-1">
                                        <div className="flex items-center gap-2">
                                            <span
                                                className={`h-2 w-2 rounded-full ${
                                                    item.status === 'healthy' ? 'bg-[#1F9B69]' : 'bg-[#DA972E]'
                                                }`}
                                            />
                                            <span className="text-[13px] font-medium text-[#171C2C]">{item.label}</span>
                                        </div>
                                        <div className="text-right">
                                            <div className="text-[12px] font-medium text-[#171C2C]">{item.value}</div>
                                            <div className="text-[11px] text-[#8C96A8]">{item.time}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Field mapping Card */}
                        <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                            <div className="mb-4">
                                <h2 className="text-[16px] font-semibold text-[#171C2C]">Field mapping</h2>
                                <p className="mt-0.5 text-[12px] text-[#8C96A8]">Source → destination mapping</p>
                            </div>

                            <div className="space-y-2.5">
                                {FIELD_MAPPINGS.map((m, idx) => (
                                    <div key={idx} className="flex items-center justify-between gap-2 text-[12px]">
                                        <span className="rounded-lg bg-[#F4F6FB] px-2.5 py-1 font-medium text-[#171C2C] flex-1 text-center truncate">
                                            {m.source}
                                        </span>
                                        <span className="text-[#4B5EF5]">→</span>
                                        <span className="rounded-lg bg-[#E9ECFF] px-2.5 py-1 font-medium text-[#4B5EF5] flex-1 text-center truncate">
                                            {m.target}
                                        </span>
                                    </div>
                                ))}
                            </div>

                            <div className="mt-5 flex items-center gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => toast.success('Field mappings configurator opened.')}
                                    className="flex-1 rounded-lg border border-[#E5E8F0] bg-white py-2 text-center text-[12px] font-medium text-[#171C2C] hover:bg-[#F8FAFD] transition-colors shadow-xs"
                                >
                                    Configure mappings
                                </button>
                                <button
                                    type="button"
                                    onClick={() => toast.success('Sync logs retrieved.')}
                                    className="flex-1 rounded-lg bg-[#E9ECFF] py-2 text-center text-[12px] font-semibold text-[#4B5EF5] hover:bg-[#DDE3FF] transition-colors"
                                >
                                    View sync logs
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Tab 2: Profile & Account */}
            {activeTab === 'profile' && (
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs max-w-2xl">
                    <h2 className="mb-4 text-[16px] font-semibold text-[#171C2C]">Account profile</h2>
                    <dl className="space-y-4">
                        <div>
                            <dt className="text-[11px] font-semibold uppercase tracking-wide text-[#8C96A8]">Name</dt>
                            <dd className="mt-1 text-[14px] font-medium text-[#171C2C]">{user?.name}</dd>
                        </div>
                        <div>
                            <dt className="text-[11px] font-semibold uppercase tracking-wide text-[#8C96A8]">Email</dt>
                            <dd className="mt-1 text-[14px] font-medium text-[#171C2C]">{user?.email}</dd>
                        </div>
                        <div>
                            <dt className="text-[11px] font-semibold uppercase tracking-wide text-[#8C96A8]">Roles</dt>
                            <dd className="mt-1 flex flex-wrap gap-1.5">
                                {(user?.roles || []).map((role) => (
                                    <span
                                        key={role}
                                        className="rounded-full bg-[#F4F6FB] px-3 py-0.5 text-[12px] font-medium capitalize text-[#171C2C]"
                                    >
                                        {role}
                                    </span>
                                ))}
                            </dd>
                        </div>
                    </dl>
                </div>
            )}

            {/* Tab 3: Notifications */}
            {activeTab === 'notifications' && (
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs max-w-2xl">
                    <h2 className="mb-2 text-[16px] font-semibold text-[#171C2C]">Email notifications</h2>
                    <p className="mb-6 text-[12px] text-[#8C96A8]">
                        Choose which task emails are delivered to you. In-app notifications are always delivered.
                    </p>
                    <NotificationPreferences />
                </div>
            )}
        </div>
    );
}