import { useTranslation } from 'react-i18next';
import Skeleton from './Skeleton';
import { Table, Td } from './Table';

/**
 * Placeholder rows that hold a table's shape while it loads. Announces
 * "Loading" once on the container instead of once per bar.
 */
export default function TableSkeleton({ columns = 4, rows = 5 }) {
    const { t } = useTranslation();

    return (
        <div role="status" aria-label={t('admin.common.loadingLabel')}>
            <Table>
                <tbody>
                    {Array.from({ length: rows }, (_, row) => (
                        <tr key={row}>
                            {Array.from({ length: columns }, (_, col) => (
                                <Td key={col}>
                                    <Skeleton decorative variant="text" className={col === 0 ? 'w-3/4' : 'w-1/2'} />
                                </Td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </Table>
        </div>
    );
}
