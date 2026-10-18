import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Input from '../../components/ui/Input';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Pagination from '../../components/ui/Pagination';
import AuditTrail from '../../components/hrms/AuditTrail';

const emptyFilters = { q: '', subject_type: '', action: '', actor_user_id: '', from: '', to: '' };

/**
 * The append-only trail, readable: a filter bar over actor, subject,
 * action, window and free text, with each row expanding to its
 * before/after diff. Read-only end to end — nothing here can rewrite
 * history, which is the point of the table.
 */
export default function AuditLog() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.auditLog.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();

    const [draft, setDraft] = useState(emptyFilters);
    const [rows, setRows] = useState(null);
    const [pagination, setPagination] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const page = Math.max(1, Number(searchParams.get('page') ?? 1) || 1);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: t('hrms.auditLog.pageTitle') }]);
    }, [setCrumbs, t]);

    const applied = Object.fromEntries(
        [...searchParams.entries()].filter(([key, value]) => key !== 'page' && value !== ''),
    );

    const load = useCallback(
        (pageNumber, params) => {
            setLoading(true);
            setError(null);

            api.get('/hrms/audit', { params: { ...params, page: pageNumber, per_page: 25 } })
                .then(({ data }) => {
                    setRows(data.audit_logs ?? []);
                    setPagination(data.pagination ?? null);
                })
                .catch((err) => {
                    if (err.response?.status === 403) {
                        navigate('/403', { replace: true });
                        return;
                    }

                    setError(t('hrms.auditLog.loadFailed'));
                })
                .finally(() => setLoading(false));
        },
        [navigate, t],
    );

    useEffect(() => {
        setDraft({ ...emptyFilters, ...applied });
        load(page, applied);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchParams.toString()]);

    function apply(e) {
        e.preventDefault();
        const params = Object.fromEntries(Object.entries(draft).filter(([, value]) => value !== ''));
        setSearchParams(params);
    }

    function reset() {
        setDraft(emptyFilters);
        setSearchParams({});
    }

    function set(field) {
        return (e) => setDraft((prev) => ({ ...prev, [field]: e.target.value }));
    }

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.auditLog.pageTitle')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">{t('hrms.auditLog.subtitle')}</p>
            </div>

            <Card title={t('hrms.auditLog.filters')} subtitle={t('hrms.auditLog.filtersHint')}>
                <form onSubmit={apply} className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <Input label={t('hrms.auditLog.searchAction')} value={draft.q} onChange={set('q')} placeholder="employee.status_changed" />
                    <Input label={t('hrms.auditLog.subjectType')} value={draft.subject_type} onChange={set('subject_type')} placeholder="App\Models\Hrms\Employee\Employee" />
                    <Input label={t('hrms.auditLog.action')} value={draft.action} onChange={set('action')} placeholder="employee.created" />
                    <Input label={t('hrms.auditLog.actorUserId')} value={draft.actor_user_id} onChange={set('actor_user_id')} placeholder="12" />
                    <Input label={t('hrms.auditLog.from')} type="date" value={draft.from} onChange={set('from')} />
                    <Input label={t('hrms.auditLog.to')} type="date" value={draft.to} onChange={set('to')} />
                    <div className="flex items-end gap-2 md:col-span-3">
                        <Button type="submit">{t('hrms.auditLog.apply')}</Button>
                        <Button type="button" variant="secondary" onClick={reset}>
                            {t('hrms.auditLog.reset')}
                        </Button>
                    </div>
                </form>
            </Card>

            {error && <Alert type="error">{error}</Alert>}

            {loading && !rows ? (
                <ContentSkeleton size="sm" />
            ) : (
                rows && (
                    <Card title={t('hrms.auditLog.trail')} subtitle={pagination ? t('hrms.auditLog.rows', { count: pagination.total }) : ''}>
                        <AuditTrail rows={rows} />
                        {pagination && (
                            <Pagination
                                page={pagination.current_page}
                                pages={pagination.last_page}
                                total={pagination.total}
                                onChange={(next) => setSearchParams({ ...applied, page: next })}
                            />
                        )}
                    </Card>
                )
            )}
        </div>
    );
}
