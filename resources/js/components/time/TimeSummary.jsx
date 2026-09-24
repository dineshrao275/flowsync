import { useEffect, useState } from 'react';
import api from '../../services/api';
import { formatMinutes } from '../../utils/time';
import Spinner from '../ui/Spinner';
import { fieldClass } from '../ui/fieldStyles';

export default function TimeSummary({ url }) {
    const [summary, setSummary] = useState(null);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({ group_by: 'user', from: '', to: '' });

    useEffect(() => {
        let active = true;
        setLoading(true);
        const params = {};
        if (filters.group_by) params.group_by = filters.group_by;
        if (filters.from) params.from = filters.from;
        if (filters.to) params.to = filters.to;
        api.get(url, { params })
            .then(({ data }) => {
                if (active) setSummary(data.summary);
            })
            .catch(() => {})
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [url, filters]);

    const maxMinutes = summary?.groups?.reduce((max, g) => Math.max(max, g.minutes), 0) || 1;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end gap-3">
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Group by</label>
                    <select
                        className={fieldClass}
                        value={filters.group_by}
                        onChange={(e) => setFilters((f) => ({ ...f, group_by: e.target.value }))}
                    >
                        <option value="user">User</option>
                        <option value="status">Status</option>
                        <option value="date">Day</option>
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">From</label>
                    <input
                        type="date"
                        className={fieldClass}
                        value={filters.from}
                        onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))}
                    />
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">To</label>
                    <input
                        type="date"
                        className={fieldClass}
                        value={filters.to}
                        onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))}
                    />
                </div>
            </div>

            {loading ? (
                <div className="flex justify-center py-10">
                    <Spinner />
                </div>
            ) : summary && summary.logs_count > 0 ? (
                <div className="space-y-4">
                    <p className="text-sm text-gray-600">
                        <span className="font-semibold text-gray-900">{formatMinutes(summary.total_minutes)}</span> logged
                        across {summary.logs_count} work log{summary.logs_count === 1 ? '' : 's'}.
                    </p>
                    <ul className="space-y-2">
                        {summary.groups.map((group) => (
                            <li key={group.key} className="rounded-lg border border-gray-100 px-3 py-2">
                                <div className="flex items-center justify-between gap-3">
                                    <p className="truncate text-sm font-medium text-gray-700">
                                        {group.label}{' '}
                                        <span className="font-normal text-gray-400">· {group.logs_count} log{group.logs_count === 1 ? '' : 's'}</span>
                                    </p>
                                    <p className="text-sm font-semibold text-gray-900">{formatMinutes(group.minutes)}</p>
                                </div>
                                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100">
                                    <div
                                        className="h-full rounded-full"
                                        style={{ width: `${Math.max(2, (group.minutes / maxMinutes) * 100)}%`, backgroundColor: 'var(--accent)' }}
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p className="py-8 text-center text-sm text-gray-500">No work logged in this period.</p>
            )}
        </div>
    );
}