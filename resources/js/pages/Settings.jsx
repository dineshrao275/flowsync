import Card from '../components/ui/Card';
import { useAuth } from '../context/AuthContext';
import usePageTitle from '../hooks/usePageTitle';

export default function Settings() {
    usePageTitle('Settings');
    const { user } = useAuth();

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
        </div>
    );
}