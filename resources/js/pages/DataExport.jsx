import { useCallback, useEffect, useRef, useState } from 'react';
import api from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import Button from '../components/ui/Button';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Badge from '../components/ui/Badge';

const ALL_CATEGORIES = [
    { key: 'workspaces', label: 'Workspaces', description: 'All workspaces and their metadata.' },
    { key: 'projects', label: 'Projects', description: 'All projects across workspaces.' },
    { key: 'tasks', label: 'Tasks', description: 'All tasks including deleted ones.' },
    { key: 'work_logs', label: 'Work logs', description: 'All time-tracking entries.' },
    { key: 'members', label: 'Members', description: 'All tenant users and their roles.' },
    { key: 'employees', label: 'Employees (HRMS)', description: 'Employee records (if HRMS is enabled).' },
    { key: 'leave_requests', label: 'Leave requests (HRMS)', description: 'Leave history (if HRMS is enabled).' },
    { key: 'payslips', label: 'Payslips (HRMS)', description: 'Payslip index — salary values are not included.' },
];

const STATUS_BADGE = {
    pending: 'warning',
    processing: 'info',
    ready: 'success',
    failed: 'danger',
};

function formatBytes(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

function formatIso(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString();
}

/**
 * Page: /export
 *
 * Gated by the `export.full` module. Allows tenant admins to queue a full data
 * export and download the ZIP once ready.
 */
export default function DataExport() {
    usePageTitle('Data Export');

    const { user } = useAuth();
    const { addToast } = useToast();

    const [runs, setRuns] = useState([]);
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [selected, setSelected] = useState(ALL_CATEGORIES.map((c) => c.key));

    const pollRef = useRef(null);

    const isAdmin = user?.roles?.includes('admin') ?? false;

    const load = useCallback(async () => {
        try {
            const res = await api.get('/my-export');
            setRuns(res.data.runs ?? []);
        } catch {
            // silently ignore load errors (background poll)
        } finally {
            setLoading(false);
        }
    }, []);

    // Poll every 5 s while any run is in progress.
    useEffect(() => {
        load();
        pollRef.current = setInterval(() => {
            const hasPending = runs.some((r) => r.status === 'pending' || r.status === 'processing');
            if (hasPending) load();
        }, 5000);
        return () => clearInterval(pollRef.current);
    }, [load, runs]);

    const toggle = (key) => {
        setSelected((prev) =>
            prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key],
        );
    };

    const queueExport = async () => {
        if (selected.length === 0) {
            addToast('Select at least one category.', 'warning');
            return;
        }
        setSubmitting(true);
        try {
            await api.post('/my-export', { categories: selected });
            addToast('Export queued. It will be ready shortly.', 'success');
            await load();
        } catch (err) {
            const msg = err?.response?.data?.message ?? 'Failed to queue export.';
            addToast(msg, 'error');
        } finally {
            setSubmitting(false);
        }
    };

    const hasInProgress = runs.some((r) => r.status === 'pending' || r.status === 'processing');

    return (
        <div className="mx-auto max-w-3xl space-y-8 px-4 py-8">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Data Export</h2>
                <p className="mt-1 text-sm text-gray-500">
                    Download a full ZIP of your tenant data. Large exports are queued and ready in minutes.
                </p>
            </div>

            {/* Category picker */}
            <section className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h3 className="mb-4 text-sm font-semibold text-gray-900">Select categories</h3>
                <div className="grid gap-3 sm:grid-cols-2">
                    {ALL_CATEGORIES.map((cat) => (
                        <label
                            key={cat.key}
                            className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors ${
                                selected.includes(cat.key)
                                    ? 'border-indigo-300 bg-indigo-50'
                                    : 'border-gray-200 hover:border-gray-300'
                            }`}
                        >
                            <input
                                id={`cat-${cat.key}`}
                                type="checkbox"
                                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                checked={selected.includes(cat.key)}
                                onChange={() => toggle(cat.key)}
                            />
                            <div>
                                <p className="text-sm font-medium text-gray-900">{cat.label}</p>
                                <p className="text-xs text-gray-400">{cat.description}</p>
                            </div>
                        </label>
                    ))}
                </div>

                <div className="mt-5 flex items-center justify-between gap-3">
                    <p className="text-xs text-gray-400">
                        {selected.length} of {ALL_CATEGORIES.length} categories selected
                    </p>
                    {isAdmin ? (
                        <Button
                            id="queue-export-btn"
                            onClick={queueExport}
                            loading={submitting}
                            disabled={submitting || hasInProgress}
                        >
                            {hasInProgress ? 'Export in progress…' : 'Queue export'}
                        </Button>
                    ) : (
                        <p className="text-xs text-gray-400">Only tenant admins can request exports.</p>
                    )}
                </div>
            </section>

            {/* Recent runs */}
            <section>
                <h3 className="mb-3 text-sm font-semibold text-gray-900">Recent exports</h3>
                {loading ? (
                    <div className="flex justify-center py-8">
                        <Spinner />
                    </div>
                ) : runs.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-gray-200 py-8 text-center text-sm text-gray-400">
                        No exports yet. Queue one above.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {runs.map((run) => (
                            <li
                                key={run.id}
                                className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant={STATUS_BADGE[run.status] ?? 'default'}>
                                            {run.status}
                                        </Badge>
                                        <span className="text-xs text-gray-400">
                                            {run.categories?.join(', ')}
                                        </span>
                                    </div>
                                    <div className="mt-1 flex flex-wrap gap-3 text-xs text-gray-400">
                                        <span>Requested {formatIso(run.created_at)}</span>
                                        {run.file_size && (
                                            <span>Size: {formatBytes(run.file_size)}</span>
                                        )}
                                        {run.expires_at && run.status === 'ready' && (
                                            <span>Expires {formatIso(run.expires_at)}</span>
                                        )}
                                    </div>
                                    {run.error && (
                                        <p className="mt-1 text-xs text-red-500">{run.error}</p>
                                    )}
                                </div>
                                <div className="shrink-0">
                                    {run.status === 'pending' || run.status === 'processing' ? (
                                        <span className="flex items-center gap-1.5 text-xs text-gray-400">
                                            <Spinner size="xs" />
                                            Building…
                                        </span>
                                    ) : run.status === 'ready' && run.download_url ? (
                                        <a
                                            id={`download-export-${run.id}`}
                                            href={run.download_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-indigo-700"
                                        >
                                            <svg
                                                className="h-3.5 w-3.5"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                            >
                                                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" />
                                            </svg>
                                            Download ZIP
                                        </a>
                                    ) : (
                                        <span className="text-xs text-gray-400">
                                            {run.status === 'ready' ? 'Link expired' : '—'}
                                        </span>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <Alert variant="info">
                <strong>Privacy note:</strong> Payslip exports include metadata only (no salary values).
                HRMS categories are empty if the module is not provisioned for your plan.
            </Alert>
        </div>
    );
}
