import { Table, Th, Td } from '../ui/Table';

/**
 * One employee's balances: what was credited, what was taken, what is left.
 *
 * Purely presentational — the `balances` array from `GET /hrms/leave/balances`
 * already carries the type and the projection columns, so this renders
 * without a second lookup.
 */
export default function LeaveBalanceTable({ balances = [] }) {
    return (
        <Table>
            <thead>
                <tr>
                    <Th>Type</Th>
                    <Th>Accrued</Th>
                    <Th>Availed</Th>
                    <Th>Encashed</Th>
                    <Th>Adjusted</Th>
                    <Th>Balance</Th>
                </tr>
            </thead>
            <tbody>
                {balances.map((row) => (
                    <tr key={row.leave_type_id}>
                        <Td>
                            <span className="font-medium text-gray-900">{row.type?.name ?? '—'}</span>
                            {!row.type?.is_paid && (
                                <span className="ml-2 text-xs text-gray-400">unpaid</span>
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
