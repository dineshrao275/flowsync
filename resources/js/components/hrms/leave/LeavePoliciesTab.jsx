import { useTranslation } from 'react-i18next';
import Button from '../../ui/Button';
import Card from '../../ui/Card';
import ContentSkeleton from '../../ui/ContentSkeleton';
import { Table, Th, Td, TableEmpty } from '../../ui/Table';

export default function LeavePoliciesTab({
    policies,
    openPolicyEditor,
    deletePolicy,
}) {
    const { t } = useTranslation();

    return (
        <Card
            dense
            title={t('hrms.leave.policiesTitle')}
            actions={<Button onClick={() => openPolicyEditor(null)}>{t('hrms.leave.newPolicy')}</Button>}
        >
            {!policies ? (
                <ContentSkeleton size="md" />
            ) : (
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('hrms.leave.thName')}</Th>
                            <Th>{t('hrms.leave.thPeriod')}</Th>
                            <Th>{t('hrms.leave.thYearStarts')}</Th>
                            <Th>{t('hrms.leave.thDefault')}</Th>
                            <Th><span className="sr-only">{t('hrms.leave.thActions')}</span></Th>
                        </tr>
                    </thead>
                    <tbody>
                        {policies.map((policy) => (
                            <tr key={policy.id}>
                                <Td><span className="font-medium">{policy.name}</span></Td>
                                <Td>{policy.accrual_period_label}</Td>
                                <Td>{t('hrms.leave.month', { month: policy.start_month })}</Td>
                                <Td>{policy.is_default ? t('hrms.leave.yes') : t('hrms.leave.no')}</Td>
                                <Td>
                                    <div className="flex justify-end gap-2">
                                        <Button variant="secondary" onClick={() => openPolicyEditor(policy)}>
                                            {t('hrms.leave.edit')}
                                        </Button>
                                        <Button variant="secondary" onClick={() => deletePolicy(policy.id)}>
                                            {t('hrms.leave.delete')}
                                        </Button>
                                    </div>
                                </Td>
                            </tr>
                        ))}
                        {policies.length === 0 && <TableEmpty colSpan={5}>{t('hrms.leave.emptyPolicies')}</TableEmpty>}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}
