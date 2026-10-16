import ErrorState from './ErrorState';
import TableSkeleton from './TableSkeleton';

/**
 * Render a `useResource`/`usePaginated` result with the three states every
 * page re-implements: first-load skeleton, error with retry, then content.
 *
 *   const res = useResource(...);
 *   <Resource {...res} skeleton={<TableSkeleton columns={5} />}>{(data) => <List data={data} />}</Resource>
 *
 * Refetches keep showing the previous data (no flash back to the skeleton);
 * only the very first load, with no data yet, shows `skeleton`.
 */
export default function Resource({ data, loading, error, reload, skeleton = <TableSkeleton />, errorTitle, children }) {
    if (data == null && error) return <ErrorState title={errorTitle} onRetry={reload} />;
    if (data == null) return loading ? skeleton : null;

    return children(data);
}
