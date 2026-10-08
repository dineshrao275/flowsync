import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../../services/api';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Input from '../../components/ui/Input';
import Spinner from '../../components/ui/Spinner';
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
    usePageTitle('Audit log');
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
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Audit log' }]);
    }, [setCrumbs]);

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

                    setError('Unable to load the audit trail.');
                })
                .finally(() => setLoading(false));
        },
        [navigate],
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
                <h2 className="text-xl font-semibold text-gray-900">Audit log</h2>
                <p className="mt-0.5 text-sm text-gray-500">Every recorded change, newest first. Values stay masked as stored.</p>
            </div>

            <Card title="Filters" subtitle="All optional — an empty bar lists the whole ledger">
                <form onSubmit={apply} className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <Input label="Search action or subject" value={draft.q} onChange={set('q')} placeholder="employee.status_changed" />
                    <Input label="Subject type" value={draft.subject_type} onChange={set('subject_type')} placeholder="App\Models\Hrms\Employee\Employee" />
                    <Input label="Action" value={draft.action} onChange={set('action')} placeholder="employee.created" />
                    <Input label="Actor user id" value={draft.actor_user_id} onChange={set('actor_user_id')} placeholder="12" />
                    <Input label="From" type="date" value={draft.from} onChange={set('from')} />
                    <Input label="To" type="date" value={draft.to} onChange={set('to')} />
                    <div className="flex items-end gap-2 md:col-span-3">
                        <Button type="submit">Apply</Button>
                        <Button type="button" variant="secondary" onClick={reset}>
                            Reset
                        </Button>
                    </div>
                </form>
            </Card>

            {error && <Alert type="error">{error}</Alert>}

            {loading && !rows ? (
                <Spinner />
            ) : (
                rows && (
                    <Card title="Trail" subtitle={pagination ? `${pagination.total} rows` : ''}>
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
