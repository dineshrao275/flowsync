import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Alert from '../components/ui/Alert';
import Spinner from '../components/ui/Spinner';
import { Table, Th, Td } from '../components/ui/Table';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { formatDateTime } from '../utils/format';

function Stat({ label, value, tone }) {
    return (
        <div className="rounded-lg border border-gray-100 px-4 py-3">
            <p className={`text-2xl font-bold ${tone || 'text-gray-900'}`}>{value}</p>
            <p className="text-xs text-gray-500">{label}</p>
        </div>
    );
}

function age(seconds, t) {
    if (seconds == null) return '—';
    if (seconds < 90) return t('admin.queue.seconds', { count: seconds });
    return t('admin.queue.minutes', { count: Math.round(seconds / 60) });
}

/** Platform console for the background queue: depth, oldest job, and failed jobs with retry / discard. */
export default function AdminQueue() {
    const { t } = useTranslation();
    usePageTitle(t('admin.queue.pageTitle'));
    useSetCrumbs([{ label: t('admin.platform.breadcrumb'), to: '/admin' }, { label: t('admin.queue.crumb') }]);
    const toast = useToast();
    const confirm = useConfirm();
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/system/queue').then(({ data: res }) => { setData(res); setError(null); }).catch(() => setError(t('admin.queue.loadFailed')));
    }, [t]);

    useEffect(() => {
        load();
        const id = setInterval(load, 15000);
        return () => clearInterval(id);
    }, [load]);

    async function retry(job) {
        try {
            const { data: res } = await api.post(`/system/queue/failed/${job.id}/retry`);
            toast.success(res.message);
            load();
        } catch {
            toast.error(t('admin.queue.actionFailed'));
        }
    }

    async function discard(job) {
        if (!(await confirm({ title: t('admin.queue.discardTitle'), message: t('admin.queue.discardMessage', { job: job.job }), variant: 'danger' }))) return;
        try {
            const { data: res } = await api.delete(`/system/queue/failed/${job.id}`);
            toast.success(res.message);
            load();
        } catch {
            toast.error(t('admin.queue.actionFailed'));
        }
    }

    if (!data) return <div className="flex justify-center py-12">{error ? <Alert>{error}</Alert> : <Spinner />}</div>;

    const q = data.queue;

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('admin.queue.pageTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('admin.queue.subtitle')}</p>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label={t('admin.queue.pending')} value={q.pending} />
                <Stat label={t('admin.queue.running')} value={q.reserved} />
                <Stat label={t('admin.queue.oldest')} value={age(q.oldest_age_seconds, t)} tone={q.oldest_age_seconds > 300 ? 'text-red-600' : undefined} />
                <Stat label={t('admin.queue.failed')} value={q.failed} tone={q.failed > 0 ? 'text-red-600' : undefined} />
            </div>

            <Card title={t('admin.queue.failedTitle')} subtitle={t('admin.queue.failedSubtitle')}>
                {data.failed_jobs.length === 0 ? (
                    <p className="py-6 text-center text-sm text-gray-500">{t('admin.queue.noneFailed')}</p>
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>{t('admin.queue.job')}</Th><Th>{t('admin.queue.tenant')}</Th><Th>{t('admin.queue.failedAt')}</Th><Th>{t('admin.queue.error')}</Th><Th><span className="sr-only">{t('admin.common.actions')}</span></Th></tr>
                        </thead>
                        <tbody>
                            {data.failed_jobs.map((job) => (
                                <tr key={job.id}>
                                    <Td><span className="font-mono text-xs">{job.job}</span><div className="text-xs text-gray-400">{job.queue}</div></Td>
                                    <Td>{job.tenant_id ?? '—'}</Td>
                                    <Td>{formatDateTime(job.failed_at)}</Td>
                                    <Td className="max-w-xs truncate" title={job.error}>{job.error}</Td>
                                    <Td>
                                        <div className="flex gap-2">
                                            <Button size="sm" variant="secondary" onClick={() => retry(job)}>{t('admin.queue.retry')}</Button>
                                            <Button size="sm" variant="ghost" onClick={() => discard(job)}>{t('admin.queue.discard')}</Button>
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </div>
    );
}
