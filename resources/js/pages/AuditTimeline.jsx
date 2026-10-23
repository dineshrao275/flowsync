import ListSavedFilters from '../components/ui/ListSavedFilters';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import usePageTitle from '../hooks/usePageTitle';
import useUrlState from '../hooks/useUrlState';
import { formatDateTime } from '../utils/format';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import Input from '../components/ui/Input';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Alert from '../components/ui/Alert';
import Resource from '../components/ui/Resource';
import { useResource } from '../hooks/useResource';
import Pagination from '../components/ui/Pagination';
import { fieldClass } from '../components/ui/fieldStyles';

/** One timeline over task activity and the HR audit ledger, filterable and exportable. */
export default function AuditTimeline() {
    const { t } = useTranslation();
    usePageTitle(t('admin.auditTimeline.title'));
    const [filters, setFilters] = useUrlState({ source: '', action: '', from: '', to: '', page: '1' });
    const params = Object.fromEntries(Object.entries({ ...filters, page: filters.page || 1 }).filter(([, v]) => v !== '' && v != null));
    const key = JSON.stringify(params);

    const res = useResource((signal) => api.get('/audit/timeline', { params: JSON.parse(key), signal }).then(({ data: body }) => body), [key]);
    const { data, error } = res;

    async function exportCsv() {
        const rest = JSON.parse(key);
        delete rest.page;
        const res = await api.get('/audit/timeline/export', { params: rest, responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'audit-timeline.csv';
        a.click();
        URL.revokeObjectURL(url);
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('admin.auditTimeline.title')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('admin.auditTimeline.subtitle')}</p>
                </div>
                <div className="flex items-center gap-2">
                    <ListSavedFilters
                        page="audit-timeline"
                        value={{ source: filters.source, action: filters.action, from: filters.from, to: filters.to }}
                        onApply={(saved) => setFilters({ source: saved.source || '', action: saved.action || '', from: saved.from || '', to: saved.to || '' })}
                    />
                    <Button variant="secondary" onClick={exportCsv}>{t('admin.auditTimeline.export')}</Button>
                </div>
            </div>

            <Card>
                <div className="grid gap-3 sm:grid-cols-4">
                    <div>
                        <label htmlFor="audit-source" className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.auditTimeline.source')}</label>
                        <select id="audit-source" className={fieldClass} value={filters.source} onChange={(e) => setFilters({ source: e.target.value })}>
                            <option value="">{t('admin.auditTimeline.all')}</option>
                            <option value="activity">{t('admin.auditTimeline.activity')}</option>
                            {(data?.sources || []).includes('hrms') && <option value="hrms">{t('admin.auditTimeline.hrms')}</option>}
                        </select>
                    </div>
                    <Input label={t('admin.auditTimeline.action')} value={filters.action} onChange={(e) => setFilters({ action: e.target.value })} />
                    <Input label={t('admin.auditTimeline.from')} type="date" value={filters.from} onChange={(e) => setFilters({ from: e.target.value })} />
                    <Input label={t('admin.auditTimeline.to')} type="date" value={filters.to} onChange={(e) => setFilters({ to: e.target.value })} />
                </div>
            </Card>

            {/* A failed refetch keeps the previous page on screen and says so; a failed first load gets the retry state. */}
            {error && data && <Alert>{t('admin.auditTimeline.loadFailed')}</Alert>}
            <Resource {...res} skeleton={<ContentSkeleton size="lg" />} errorTitle={t('admin.auditTimeline.loadFailed')}>
                {() => (
                <Card>
                    {data.entries.length === 0 ? <p className="py-8 text-center text-sm text-gray-500">{t('admin.auditTimeline.empty')}</p> : (
                        <ul className="divide-y divide-gray-100">
                            {data.entries.map((e) => (
                                <li key={`${e.source}-${e.id}`} className="py-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge tone={e.source === 'hrms' ? 'accent' : 'info'}>{t(`admin.auditTimeline.${e.source === 'hrms' ? 'hrms' : 'activity'}`)}</Badge>
                                        <span className="font-medium text-gray-900">{e.action}</span>
                                        <span className="text-sm text-gray-500">{e.subject_type} #{e.subject_id}</span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-gray-500">{formatDateTime(e.created_at)} · {e.actor || t('admin.auditTimeline.system')}{e.ip_address ? ` · ${e.ip_address}` : ''}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Pagination page={data.pagination.current_page} pages={data.pagination.last_page} total={data.pagination.total} onChange={(p) => setFilters({ page: p })} />
                </Card>
                )}
            </Resource>
        </div>
    );
}
