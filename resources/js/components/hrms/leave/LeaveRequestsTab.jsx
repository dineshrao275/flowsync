import { useTranslation } from 'react-i18next';
import Button from '../../ui/Button';
import Card from '../../ui/Card';
import ContentSkeleton from '../../ui/ContentSkeleton';
import EmptyState from '../../ui/EmptyState';
import Select from '../../ui/Select';
import { Table, Th, Td } from '../../ui/Table';

const REQUEST_STATUSES = [
    { value: '', key: 'stAllOpen' },
    { value: 'submitted', key: 'stSubmitted' },
    { value: 'pending', key: 'stPending' },
    { value: 'approved', key: 'stApproved' },
    { value: 'rejected', key: 'stRejected' },
    { value: 'cancelled', key: 'stCancelled' },
];

export default function LeaveRequestsTab({
    requests,
    requestStatus,
    setRequestStatus,
    onStartDecision,
}) {
    const { t } = useTranslation();

    return (
        <Card dense>
            <div className="mb-3 flex items-center gap-2">
                <Select
                    aria-label={t('hrms.leave.statusFilter')}
                    value={requestStatus}
                    onChange={(e) => setRequestStatus(e.target.value)}
                    className="w-44"
                >
                    {REQUEST_STATUSES.map((option) => (
                        <option key={option.value} value={option.value}>
                            {t(`hrms.leave.${option.key}`)}
                        </option>
                    ))}
                </Select>
            </div>

            {!requests ? (
                <ContentSkeleton size="md" />
            ) : requests.length === 0 ? (
                <EmptyState title={t('hrms.leave.emptyTitle')} description={t('hrms.leave.emptyHint')} />
            ) : (
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('hrms.leave.thEmployee')}</Th>
                            <Th>{t('hrms.leave.thDates')}</Th>
                            <Th>{t('hrms.leave.thType')}</Th>
                            <Th>{t('hrms.leave.thDays')}</Th>
                            <Th>{t('hrms.leave.thStatus')}</Th>
                            <Th><span className="sr-only">{t('hrms.leave.thActions')}</span></Th>
                        </tr>
                    </thead>
                    <tbody>
                        {requests.map((request) => (
                            <tr key={request.id}>
                                <Td>{request.employee?.name ?? '—'}</Td>
                                <Td>{request.from_date} → {request.to_date}</Td>
                                <Td>{request.type?.name ?? '—'}</Td>
                                <Td>{request.total_days}</Td>
                                <Td>{request.status_label}</Td>
                                <Td>
                                    {request.status === 'submitted' || request.status === 'pending' ? (
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                variant="secondary"
                                                onClick={() => onStartDecision(request, 'approve')}
                                            >
                                                {t('hrms.leave.approve')}
                                            </Button>
                                            <Button
                                                variant="secondary"
                                                onClick={() => onStartDecision(request, 'reject')}
                                            >
                                                {t('hrms.leave.reject')}
                                            </Button>
                                        </div>
                                    ) : null}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}
