import { useTranslation } from 'react-i18next';
import { Table, Th, Td } from '../ui/Table';

/**
 * One employee's balances: what was credited, what was taken, what is left.
 *
 * Purely presentational — the `balances` array from `GET /hrms/leave/balances`
 * already carries the type and the projection columns, so this renders
 * without a second lookup.
 */
export default function LeaveBalanceTable({ balances = [] }) {
    const { t } = useTranslation();
    return (
        <Table compact>
            <thead>
                <tr>
                    <Th>{t('hrms.leaveBalance.thType')}</Th>
                    <Th>{t('hrms.leaveBalance.thAccrued')}</Th>
                    <Th>{t('hrms.leaveBalance.thAvailed')}</Th>
                    <Th>{t('hrms.leaveBalance.thEncashed')}</Th>
                    <Th>{t('hrms.leaveBalance.thAdjusted')}</Th>
                    <Th>{t('hrms.leaveBalance.thBalance')}</Th>
                </tr>
            </thead>
            <tbody>
                {balances.map((row) => (
                    <tr key={row.leave_type_id}>
                        <Td>
                            <span className="font-medium text-gray-900">{row.type?.name ?? '—'}</span>
                            {!row.type?.is_paid && (
                                <span className="ml-2 text-xs text-gray-400">{t('hrms.leaveBalance.unpaid')}</span>
                            )}
                        </Td>
                        <Td>{row.accrued}</Td>
                        <Td>{row.availed}</Td>
                        <Td>{row.encashed}</Td>
                        <Td>{row.adjusted}</Td>
                        <Td>
                            <span className="font-semibold text-gray-900">{row.balance}</span>
                        </Td>
                    </tr>
                ))}
            </tbody>
        </Table>
    );
}
