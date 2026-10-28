import { useTranslation } from 'react-i18next';
import Button from '../../ui/Button';
import Card from '../../ui/Card';
import ContentSkeleton from '../../ui/ContentSkeleton';
import { Table, Th, Td, TableEmpty } from '../../ui/Table';

export default function LeaveTypesTab({
    types,
    openTypeEditor,
    deleteType,
}) {
    const { t } = useTranslation();

    return (
        <Card
            dense
            title={t('hrms.leave.typesTitle')}
            actions={<Button onClick={() => openTypeEditor(null)}>{t('hrms.leave.newType')}</Button>}
        >
            {!types ? (
                <ContentSkeleton size="md" />
            ) : (
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('hrms.leave.thName')}</Th>
                            <Th>{t('hrms.leave.thAccrual')}</Th>
                            <Th>{t('hrms.leave.thMax')}</Th>
                            <Th>{t('hrms.leave.thActive')}</Th>
                            <Th><span className="sr-only">{t('hrms.leave.thActions')}</span></Th>
                        </tr>
                    </thead>
                    <tbody>
                        {types.map((type) => (
                            <tr key={type.id}>
                                <Td>
                                    <span className="font-medium">{type.name}</span>
                                    {type.is_system && (
                                        <span className="ml-2 text-xs text-gray-400">
                                            {t('hrms.leave.system')}
                                        </span>
                                    )}
                                </Td>
                                <Td>{type.accrual_method_label} · {type.accrual_rate}</Td>
                                <Td>{type.max_balance ?? '—'}</Td>
                                <Td>{type.is_active ? t('hrms.leave.yes') : t('hrms.leave.no')}</Td>
                                <Td>
                                    <div className="flex justify-end gap-2">
                                        <Button variant="secondary" onClick={() => openTypeEditor(type)}>
                                            {t('hrms.leave.edit')}
                                        </Button>
                                        <Button variant="secondary" onClick={() => deleteType(type.id)}>
                                            {t('hrms.leave.delete')}
                                        </Button>
                                    </div>
                                </Td>
                            </tr>
                        ))}
                        {types.length === 0 && <TableEmpty colSpan={5}>{t('hrms.leave.emptyTypes')}</TableEmpty>}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}
