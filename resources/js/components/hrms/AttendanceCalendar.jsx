import { useTranslation } from 'react-i18next';
import { formatMinutes } from '../../utils/time';
import i18n, { intlTag } from '../../i18n';

/**
 * Hex fills for day statuses, mirroring the PHP enum labels (the server
 * ships `status` + `status_label`; the client owns the swatch, like the
 * task-board pills own theirs).
 */
export const ATTENDANCE_STATUS_COLORS = {
    present: 'var(--chart-green)',
    absent: 'var(--chart-red)',
    half_day: 'var(--chart-amber)',
    late: 'var(--chart-orange)',
    leave: 'var(--chart-sky)',
    holiday: 'var(--chart-violet)',
    week_off: 'var(--chart-neutral)',
    remote: 'var(--chart-sky)',
    inactive: 'var(--chart-neutral)',
};

/**
 * Monday-first weekday headers in the interface language (2024-01-01 was a
 * Monday, so seven consecutive days from it land Mon…Sun in every locale).
 */
function weekdayNames() {
    const tag = intlTag(i18n.language);

    return Array.from(
        { length: 7 },
        (_, i) => new Date(2024, 0, 1 + i).toLocaleDateString(tag, { weekday: 'short' }),
    );
}

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
    const { t } = useTranslation();
    const weekdays = weekdayNames();
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
        const color = entry ? ATTENDANCE_STATUS_COLORS[entry.status] ?? 'var(--chart-neutral)' : null;
        const selected = selectedDate === date;

        cells.push(
            <button
                key={date}
                type="button"
                disabled={!entry || !onSelectDay}
                onClick={() => onSelectDay?.(entry)}
                title={entry ? `${entry.status_label} · ${formatMinutes(entry.worked_minutes)}` : t('hrms.calendar.upcoming')}
                className={`flex min-h-14 flex-col items-start justify-between rounded-lg border p-1.5 text-left transition-colors ${
                    selected
                        ? 'border-[var(--accent)] ring-1 ring-[var(--accent-ring)]'
                        : 'border-gray-100 hover:border-gray-300'
                } ${entry ? '' : 'bg-gray-50/50'} ${!entry || !onSelectDay ? 'cursor-default' : 'cursor-pointer'}`}
            >
                <span className="text-xs font-medium text-gray-500">{day}</span>
                {entry ? (
                    <span className="w-full">
                        <span
                            className="mb-0.5 block h-1.5 w-full rounded-full"
                            style={{ backgroundColor: `color-mix(in srgb, ${color} 20%, transparent)` }}
                        >
                            <span className="block h-full rounded-full" style={{ backgroundColor: color, width: '100%' }} />
                        </span>
                        {/* The bar carries the status hue; the label stays
                            dark — 11px colored text on white fails contrast
                            for every hue, while the bar needs no text. */}
                        <span className="block truncate text-xs font-medium text-gray-700">
                            {entry.status_label}
                            {entry.is_regularized ? ' ·R' : ''}
                        </span>
                        <span className="block text-xs text-gray-500">{formatMinutes(entry.worked_minutes)}</span>
                    </span>
                ) : (
                    <span className="text-xs text-gray-400">—</span>
                )}
            </button>,
        );
    }

    return (
        <div>
            <div className="mb-1 grid grid-cols-7 gap-1">
                {weekdays.map((day) => (
                    <span key={day} className="pb-1 text-center text-xs font-medium text-gray-400">
                        {day}
                    </span>
                ))}
            </div>
            <div className="grid grid-cols-7 gap-1">{cells}</div>
        </div>
    );
}
