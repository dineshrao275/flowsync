import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import Timestamp from '../../components/ui/Timestamp';
import UserChip from '../../components/ui/UserChip';
import StatusBadge from '../../components/ui/StatusBadge';

const STATUS_OPTIONS = [
    { value: '', key: 'optPending' },
    { value: 'approved', key: 'optApproved' },
    { value: 'rejected', key: 'optRejected' },
];

/**
 * The regularization queue: correction asks awaiting a decision, and the
 * trail of decided ones.
 *
 * The route is gated on `hrms.attendance.regularize`, but deciding still
 * belongs to the approval step's approver — the backend answers 403 for a
 * permission holder who is not on the chain, and the toast says exactly
 * that rather than a generic failure. Rejection requires a reason; the
 * employee sees it on resubmission.
 */
export default function AttendanceApprovals() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.approvals.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [status, setStatus] = useState('');
    const [requests, setRequests] = useState(null);
    const [error, setError] = useState(null);
    const [deciding, setDeciding] = useState(null);
    const [note, setNote] = useState('');
    const [noteErrors, setNoteErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.attendance.pageTitle'), to: '/hrms/attendance' }, { label: t('hrms.approvals.crumbApprovals') }]);
    }, [setCrumbs, t]);

    const load = useCallback(() => {
        setError(null);

        const params = status ? { status } : { status: 'pending' };

        return api
            .get('/hrms/attendance/regularizations', { params })
            .then(({ data }) => setRequests(data.requests ?? []))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.approvals.loadFailed'));
            });
    }, [status, navigate, t]);

    useEffect(() => {
        load();
    }, [load]);

    function openDecide(request, verdict) {
        setDeciding({ ...request, verdict });
        setNote('');
        setNoteErrors({});
    }

    async function submitDecide(e) {
        e.preventDefault();
        setNoteErrors({});

        if (!deciding) return;

        setSaving(true);

        try {
            await api.post(`/hrms/attendance/regularizations/${deciding.id}/${deciding.verdict}`, { note: note || null });

            toast.success(deciding.verdict === 'approve' ? t('hrms.approvals.approved') : t('hrms.approvals.rejected'));
            setDeciding(null);
            load();
        } catch (err) {
            if (err.response?.status === 403) {
                toast.error(t('hrms.approvals.forbidden'));
                return;
            }

            setNoteErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.approvals.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {requests ? (requests.length === 1 ? t('hrms.approvals.requestsOne') : t('hrms.approvals.requests', { count: requests.length })) : '—'}
                    </p>
                </div>

                <Select aria-label={t('hrms.approvals.statusFilter')} value={status} onChange={(e) => setStatus(e.target.value)} className="w-44">
                    {STATUS_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {t(`hrms.approvals.${option.key}`)}
                        </option>
                    ))}
                </Select>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense>
                {!requests ? (
                    <ContentSkeleton size="md" />
                ) : requests.length === 0 ? (
                    <EmptyState title={t('hrms.approvals.emptyTitle')} description={t('hrms.approvals.emptyHint')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.approvals.thEmployee')}</Th>
                                <Th>{t('hrms.approvals.thDate')}</Th>
                                <Th>{t('hrms.approvals.thTimes')}</Th>
                                <Th>{t('hrms.approvals.thReason')}</Th>
                                <Th>{t('hrms.approvals.thStatus')}</Th>
                                <Th><span className="sr-only">{t('hrms.approvals.thActions')}</span></Th>
                            </tr>
                        </thead>
                        <tbody>
                            {requests.map((request) => (
                                <tr key={request.id}>
                                    <Td>{request.employee ? <UserChip user={request.employee} /> : '—'}</Td>
                                    <Td>{request.work_date}</Td>
                                    <Td>
                                        <Timestamp date={request.requested_first_in_at} />
                                        {' → '}
                                        <Timestamp date={request.requested_punch_at} />
                                    </Td>
                                    <Td>
                                        <span className="block max-w-xs truncate" title={request.reason}>
                                            {request.reason}
                                        </span>
                                    </Td>
                                    <Td><StatusBadge status={request.status_label || request.status} /></Td>
                                    <Td>
                                        {request.status === 'pending' && (
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => openDecide(request, 'approve')}>
                                                    {t('hrms.approvals.approve')}
                                                </Button>
                                                <Button variant="secondary" onClick={() => openDecide(request, 'reject')}>
                                                    {t('hrms.approvals.reject')}
                                                </Button>
                                            </div>
                                        )}
                                        {request.status !== 'pending' && request.decided_by && (
                                            <span className="text-xs text-gray-500">{t('hrms.approvals.decidedBy', { name: request.decided_by.name })}</span>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Modal
                open={!!deciding}
                onClose={() => setDeciding(null)}
                title={deciding?.verdict === 'approve' ? t('hrms.approvals.approveTitle', { date: deciding?.work_date }) : t('hrms.approvals.rejectTitle', { date: deciding?.work_date })}
            >
                <form onSubmit={submitDecide} className="space-y-3 px-6 py-5">
                    <p className="text-sm text-gray-500">
                        {deciding?.verdict === 'approve'
                            ? t('hrms.approvals.approveIntro')
                            : t('hrms.approvals.rejectIntro')}
                    </p>
                    <Input
                        label={deciding?.verdict === 'approve' ? t('hrms.approvals.noteOptional') : t('hrms.approvals.reason')}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        error={noteErrors.note ?? noteErrors.decision_note}
                        placeholder={deciding?.verdict === 'approve' ? t('hrms.approvals.approvePlaceholder') : t('hrms.approvals.rejectPlaceholder')}
                    />
                    {noteErrors.form && <Alert>{noteErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setDeciding(null)}>{t('hrms.approvals.cancel')}</Button>
                        <Button type="submit" disabled={saving}>
                            {saving ? t('hrms.approvals.saving') : deciding?.verdict === 'approve' ? t('hrms.approvals.approve') : t('hrms.approvals.reject')}
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
