import { useTranslation } from 'react-i18next';
import Button from '../../ui/Button';
import Card from '../../ui/Card';
import ContentSkeleton from '../../ui/ContentSkeleton';
import EmptyState from '../../ui/EmptyState';
import { Table, Th, Td } from '../../ui/Table';

export default function LeaveExemptionsTab({
    exemptions,
    onFileExemption,
    decideExemption,
}) {
    const { t } = useTranslation();

    return (
        <>
            <div className="flex justify-end">
                <Button onClick={onFileExemption}>
                    {t('hrms.leave.fileExemption')}
                </Button>
            </div>
            <Card dense title={t('hrms.leave.exemptionsTitle')}>
                {!exemptions ? (
                    <ContentSkeleton size="md" />
                ) : exemptions.length === 0 ? (
                    <EmptyState
                        title={t('hrms.leave.exemptionsEmptyTitle')}
                        description={t('hrms.leave.exemptionsEmptyHint')}
                    />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.leave.thEmployee')}</Th>
                                <Th>{t('hrms.leave.thDates')}</Th>
                                <Th>{t('hrms.leave.thDays')}</Th>
                                <Th>{t('hrms.leave.thStatus')}</Th>
                                <Th><span className="sr-only">{t('hrms.leave.thActions')}</span></Th>
                            </tr>
                        </thead>
                        <tbody>
                            {exemptions.map((exemption) => (
                                <tr key={exemption.id}>
                                    <Td>{exemption.employee?.name ?? '—'}</Td>
                                    <Td>{exemption.from_date} → {exemption.to_date}</Td>
                                    <Td>{exemption.days}</Td>
                                    <Td>{exemption.status_label}</Td>
                                    <Td>
                                        {exemption.status === 'pending' && (
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    variant="secondary"
                                                    onClick={() => decideExemption(exemption.id, 'approve')}
                                                >
                                                    {t('hrms.leave.approve')}
                                                </Button>
                                                <Button
                                                    variant="secondary"
                                                    onClick={() => decideExemption(exemption.id, 'reject')}
                                                >
                                                    {t('hrms.leave.reject')}
                                                </Button>
                                            </div>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </>
    );
}
