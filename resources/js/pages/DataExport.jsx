import { useCallback, useEffect, useRef, useState } from 'react';
import { Trans, useTranslation } from 'react-i18next';
import api from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import Button from '../components/ui/Button';
import Spinner from '../components/ui/Spinner';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Alert from '../components/ui/Alert';
import Badge from '../components/ui/Badge';
import Timestamp from '../components/ui/Timestamp';

const ALL_CATEGORIES = [
    { key: 'workspaces', tKey: 'workspaces' },
    { key: 'projects', tKey: 'projects' },
    { key: 'tasks', tKey: 'tasks' },
    { key: 'work_logs', tKey: 'workLogs' },
    { key: 'members', tKey: 'members' },
    { key: 'employees', tKey: 'employees' },
    { key: 'leave_requests', tKey: 'leaveRequests' },
    { key: 'payslips', tKey: 'payslips' },
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

/**
 * Page: /export
 *
 * Gated by the `export.full` module. Allows tenant admins to queue a full data
 * export and download the ZIP once ready.
 */
export default function DataExport() {
    const { t } = useTranslation();
    usePageTitle(t('admin.dataExport.pageTitle'));

    const { user } = useAuth();
    const toast = useToast();

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

    // Poll every 5 s while any run is in progress. The interval (re)arms
    // only when the run list changes, and each tick refetches only while
    // something is still pending — depending on `runs` with an eager
    // load() inside (the previous shape) refetched on every identical
    // payload and hammered the endpoint.
    useEffect(() => {
        const hasPending = runs.some((r) => r.status === 'pending' || r.status === 'processing');
        if (!hasPending) return undefined;
        pollRef.current = setInterval(load, 5000);
        return () => clearInterval(pollRef.current);
    }, [load, runs]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const toggle = (key) => {
        setSelected((prev) =>
            prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key],
        );
    };

    const queueExport = async () => {
        if (selected.length === 0) {
            toast.warning(t('admin.dataExport.selectAtLeastOne'));
            return;
        }
        setSubmitting(true);
        try {
            await api.post('/my-export', { categories: selected });
            toast.success(t('admin.dataExport.queued'));
            await load();
        } catch (err) {
            const msg = err?.response?.data?.message ?? t('admin.dataExport.queueFailed');
            toast.error(msg);
        } finally {
            setSubmitting(false);
        }
    };

    const hasInProgress = runs.some((r) => r.status === 'pending' || r.status === 'processing');

    return (
        <div className="mx-auto max-w-3xl space-y-8 px-4 py-8">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('admin.dataExport.title')}</h2>
                <p className="mt-1 text-sm text-gray-500">
                    {t('admin.dataExport.subtitle')}
                </p>
            </div>

            {/* Category picker */}
            <section className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h3 className="mb-4 text-sm font-semibold text-gray-900">{t('admin.dataExport.selectCategories')}</h3>
                <div className="grid gap-3 sm:grid-cols-2">
                    {ALL_CATEGORIES.map((cat) => (
                        <label
                            key={cat.key}
                            className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors ${
                                selected.includes(cat.key)
                                    ? 'border-[var(--accent-ring)] bg-[var(--accent-soft)]'
                                    : 'border-gray-200 hover:border-gray-300'
                            }`}
                        >
                            <input
                                id={`cat-${cat.key}`}
                                type="checkbox"
                                className="mt-0.5 h-4 w-4 rounded border-gray-300 accent-[var(--accent)]"
                                checked={selected.includes(cat.key)}
                                onChange={() => toggle(cat.key)}
                            />
                            <div>
                                <p className="text-sm font-medium text-gray-900">{t(`admin.dataExport.categories.${cat.tKey}Label`)}</p>
                                <p className="text-xs text-gray-400">{t(`admin.dataExport.categories.${cat.tKey}Description`)}</p>
                            </div>
                        </label>
                    ))}
                </div>

                <div className="mt-5 flex items-center justify-between gap-3">
                    <p className="text-xs text-gray-400">
                        {t('admin.dataExport.selectedCount', { selected: selected.length, total: ALL_CATEGORIES.length })}
                    </p>
                    {isAdmin ? (
                        <Button
                            id="queue-export-btn"
                            onClick={queueExport}
                            loading={submitting}
                            disabled={submitting || hasInProgress}
                        >
                            {hasInProgress ? t('admin.dataExport.exportInProgress') : t('admin.dataExport.queueExport')}
                        </Button>
                    ) : (
                        <p className="text-xs text-gray-400">{t('admin.dataExport.adminsOnly')}</p>
                    )}
                </div>
            </section>

            {/* Recent runs */}
            <section>
                <h3 className="mb-3 text-sm font-semibold text-gray-900">{t('admin.dataExport.recentExports')}</h3>
                {loading ? (
                    <ContentSkeleton size="md" />
                ) : runs.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-gray-200 py-8 text-center text-sm text-gray-400">
                        {t('admin.dataExport.empty')}
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
                                        <span>{t('admin.dataExport.requested')} <Timestamp date={run.created_at} /></span>
                                        {run.file_size && (
                                            <span>{t('admin.dataExport.size', { size: formatBytes(run.file_size) })}</span>
                                        )}
                                        {run.expires_at && run.status === 'ready' && (
                                            <span>{t('admin.dataExport.expires')} <Timestamp date={run.expires_at} /></span>
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
                                            {t('admin.dataExport.building')}
                                        </span>
                                    ) : run.status === 'ready' && run.download_url ? (
                                        <a
                                            id={`download-export-${run.id}`}
                                            href={run.download_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-[var(--accent)] px-3 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-[var(--accent-hover)]"
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
                                            {t('admin.dataExport.downloadZip')}
                                        </a>
                                    ) : (
                                        <span className="text-xs text-gray-400">
                                            {run.status === 'ready' ? t('admin.dataExport.linkExpired') : '—'}
                                        </span>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <Alert variant="info">
                <Trans i18nKey="admin.dataExport.privacyNote" components={[<strong key="0" />]} />
            </Alert>
        </div>
    );
}
