import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Spinner from '../../components/ui/Spinner';
import { useToast } from '../../context/ToastContext';
import { useAuth } from '../../context/AuthContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import ExpenseClaimModal from '../../components/hrms/ExpenseClaimModal';

/**
 * The caller's own claims: file, re-line drafts, submit, and watch the
 * chain decide.
 *
 * Self-scoped on purpose — this page rides the module alone like My files,
 * because it only ever shows the caller's rows. The employment record comes
 * from the directory lookup by login, so there is no picker and no way to
 * file into someone else's name.
 */
export default function MyExpenses() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.myExpenses.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const { user } = useAuth();

    const [claims, setClaims] = useState(null);
    const [categories, setCategories] = useState([]);
    const [types, setTypes] = useState([]);
    const [employeeId, setEmployeeId] = useState(null);
    const [error, setError] = useState(null);

    const [modal, setModal] = useState(false);
    const [editing, setEditing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: t('hrms.myExpenses.pageTitle') }]);
    }, [setCrumbs, t]);

    const load = useCallback(() => {
        setError(null);

        return api
            .get('/hrms/expenses/claims')
            .then(({ data }) => setClaims(data.claims ?? []))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.myExpenses.loadFailed'));
            });
    }, [navigate, t]);

    useEffect(() => {
        load();

        api.get('/hrms/expenses/categories')
            .then(({ data }) => setCategories((data.categories ?? []).filter((c) => c.is_active)))
            .catch(() => setCategories([]));

        api.get('/hrms/documents/types')
            .then(({ data }) => setTypes(data.document_types ?? []))
            .catch(() => setTypes([]));

        // The record this login files under: the directory row whose user
        // block names the login. No picker, so no way to file into
        // someone else's name — and when the directory is unreachable the
        // file button simply stays hidden.
        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployeeId((data.employees ?? []).find((e) => e.user?.id === user?.id)?.id ?? null))
            .catch(() => setEmployeeId(null));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function submit(claim) {
        try {
            await api.post(`/hrms/expenses/claims/${claim.id}/submit`, {});
            toast.success(t('hrms.myExpenses.submitted'));
            load();
        } catch {
            setError(t('hrms.myExpenses.submitFailed'));
        }
    }

    function openNew() {
        setEditing(null);
        setModal(true);
    }

    function openEdit(claim) {
        setEditing(claim);
        setModal(true);
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.myExpenses.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {claims ? (claims.length === 1 ? t('hrms.myExpenses.claimCountOne') : t('hrms.myExpenses.claimCount', { count: claims.length })) : '—'}
                    </p>
                </div>
                {employeeId && <Button onClick={openNew}>{t('hrms.myExpenses.fileClaim')}</Button>}
            </div>

            {error && <Alert>{error}</Alert>}

            {!claims ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : claims.length === 0 ? (
                <EmptyState title={t('hrms.myExpenses.emptyTitle')} hint={t('hrms.myExpenses.emptyHint')} />
            ) : (
                <Card dense>
                    <ul className="divide-y divide-gray-100">
                        {claims.map((claim) => (
                            <li key={claim.id} className="py-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <span className="font-medium text-gray-900">{claim.claim_number}</span>
                                        <span className="ml-2 text-xs text-gray-400">{claim.status}</span>
                                        <p className="text-sm text-gray-500">{claim.purpose} · {claim.total_amount}</p>
                                    </div>
                                    <div className="flex gap-2">
                                        {claim.status === 'draft' && (
                                            <>
                                                <Button size="sm" variant="secondary" onClick={() => openEdit(claim)}>{t('hrms.myExpenses.lines')}</Button>
                                                <Button size="sm" onClick={() => submit(claim)}>{t('hrms.common.submit')}</Button>
                                            </>
                                        )}
                                    </div>
                                </div>
                                <ul className="mt-1 space-y-0.5 text-sm text-gray-500">
                                    {(claim.items ?? []).map((item) => (
                                        <li key={item.id}>
                                            {item.description} · {item.amount}
                                            {item.receipt_document_id ? ` · ${t('hrms.myExpenses.receiptAttached')}` : ''}
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <ExpenseClaimModal
                open={modal}
                onClose={() => setModal(false)}
                onSaved={() => {
                    setModal(false);
                    load();
                }}
                employeeId={employeeId}
                categories={categories}
                types={types}
                claim={editing}
            />
        </div>
    );
}
