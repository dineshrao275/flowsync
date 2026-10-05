import { formatMinutes } from '../../utils/time';

/**
 * Hex fills for day statuses, mirroring the PHP enum labels (the server
 * ships `status` + `status_label`; the client owns the swatch, like the
 * task-board pills own theirs).
 */
export const ATTENDANCE_STATUS_COLORS = {
    present: '#059669',
    absent: '#dc2626',
    half_day: '#d97706',
    late: '#ea580c',
    leave: '#0284cb',
    holiday: '#7c3aed',
    week_off: '#6b7280',
    remote: '#0891b2',
    inactive: '#9ca3af',
};

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/**
 * A reusable month grid for attendance days.
 *
 * Purely presentational: `days` is the `days` array from
 * `GET /hrms/attendance/month` (keyed here by date), and every interaction
 * flows back out through `onSelectDay`. The grid blanks dates the backend
 * did not return (future days) as upcoming rather than inventing a status
 * for them.
 */
export default function AttendanceCalendar({ year, month, days = [], selectedDate = null, onSelectDay = null }) {
    const byDate = new Map(days.map((day) => [day.date, day]));

    // Monday-first offset: JS Sundays are 0, the grid starts on Monday.
    const firstOffset = (new Date(year, month - 1, 1).getDay() + 6) % 7;
    const daysInMonth = new Date(year, month, 0).getDate();

    const cells = [];

    for (let i = 0; i < firstOffset; i++) {
        cells.push(<span key={`pad-${i}`} />);
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const date = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const entry = byDate.get(date) ?? null;
        const color = entry ? ATTENDANCE_STATUS_COLORS[entry.status] ?? '#6b7280' : null;
        const selected = selectedDate === date;

        cells.push(
            <button
                key={date}
                type="button"
                disabled={!entry || !onSelectDay}
                onClick={() => onSelectDay?.(entry)}
                title={entry ? `${entry.status_label} · ${formatMinutes(entry.worked_minutes)}` : 'Upcoming'}
                className={`flex min-h-14 flex-col items-start justify-between rounded-lg border p-1.5 text-left transition-colors ${
                    selected
                        ? 'border-indigo-500 ring-1 ring-indigo-200'
                        : 'border-gray-100 hover:border-gray-300'
                } ${entry ? '' : 'bg-gray-50/50'} ${!entry || !onSelectDay ? 'cursor-default' : 'cursor-pointer'}`}
            >
                <span className="text-xs font-medium text-gray-500">{day}</span>
                {entry ? (
                    <span className="w-full">
                        <span
                            className="mb-0.5 block h-1.5 w-full rounded-full"
                            style={{ backgroundColor: `${color}33` }}
                        >
                            <span className="block h-full rounded-full" style={{ backgroundColor: color, width: '100%' }} />
                        </span>
                        {/* The bar carries the status hue; the label stays
                            dark — 11px colored text on white fails contrast
                            for every hue, while the bar needs no text. */}
                        <span className="block truncate text-[11px] font-medium text-gray-700">
                            {entry.status_label}
                            {entry.is_regularized ? ' ·R' : ''}
                        </span>
                        <span className="block text-[11px] text-gray-500">{formatMinutes(entry.worked_minutes)}</span>
                    </span>
                ) : (
                    <span className="text-[11px] text-gray-300">—</span>
                )}
            </button>,
        );
    }

    return (
        <div>
            <div className="mb-1 grid grid-cols-7 gap-1">
                {WEEKDAYS.map((day) => (
                    <span key={day} className="pb-1 text-center text-xs font-medium text-gray-400">
                        {day}
                    </span>
                ))}
            </div>
            <div className="grid grid-cols-7 gap-1">{cells}</div>
        </div>
    );
}
