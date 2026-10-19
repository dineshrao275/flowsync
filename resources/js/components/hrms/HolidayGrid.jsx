import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import i18n, { intlTag } from '../../i18n';

const TYPE_COLORS = {
    public: '#059669',
    restricted: '#d97706',
    optional: '#0284cb',
};

const TYPE_KEYS = {
    public: 'typePublic',
    restricted: 'typeRestricted',
    optional: 'typeOptional',
};

/** Full month names in the interface language for the month tabs. */
function monthNames() {
    const tag = intlTag(i18n.language);

    return Array.from(
        { length: 12 },
        (_, i) => new Date(2024, i, 1).toLocaleDateString(tag, { month: 'long' }),
    );
}

/** Single-letter Monday-first weekday initials in the interface language. */
function weekdayInitials() {
    const tag = intlTag(i18n.language);

    return Array.from(
        { length: 7 },
        (_, i) => new Date(2024, 0, 1 + i).toLocaleDateString(tag, { weekday: 'narrow' }),
    );
}

/**
 * A year grid for one calendar's holidays, with month tabs.
 *
 * Recurring rows expand client-side by month/day (the server stores the
 * current year's occurrence; the month/day is what recurs), so the grid
 * shows any year without refetching. Non-recurring rows render only in
 * their own year.
 */
export default function HolidayGrid({ holidays = [], year }) {
    const { t } = useTranslation();
    const [month, setMonth] = useState(new Date().getMonth() + 1);

    const byDate = useMemo(() => {
        const map = {};

        for (const holiday of holidays) {
            const [, storedMonth, storedDay] = holiday.date.split('-').map(Number);

            const dates = holiday.is_recurring
                ? [`${year}-${String(storedMonth).padStart(2, '0')}-${String(storedDay).padStart(2, '0')}`]
                : [holiday.date];

            for (const date of dates) {
                if (!date.startsWith(`${year}-`)) continue;

                (map[date] = map[date] ?? []).push(holiday);
            }
        }

        return map;
    }, [holidays, year]);

    const firstOffset = (new Date(year, month - 1, 1).getDay() + 6) % 7;
    const daysInMonth = new Date(year, month, 0).getDate();

    const cells = [];

    for (let i = 0; i < firstOffset; i++) {
        cells.push(<span key={`pad-${i}`} />);
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const date = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const entries = byDate[date] ?? [];
        const color = entries.length > 0 ? TYPE_COLORS[entries[0].type] ?? '#6b7280' : null;

        cells.push(
            <span
                key={date}
                title={entries.length === 0 ? date : entries.map((e) => `${e.name} (${e.type})`).join(', ')}
                className={`flex min-h-10 flex-col items-center justify-center rounded-lg border text-xs ${
                    entries.length === 0 ? 'border-gray-100 text-gray-500' : 'border-transparent font-semibold text-gray-900'
                }`}
                style={entries.length === 0 ? {} : { backgroundColor: `${color}33` }}
            >
                {day}
            </span>,
        );
    }

    return (
        <div>
            <div className="mb-2 flex flex-wrap gap-1">
                {monthNames().map((name, i) => (
                    <button
                        key={name}
                        type="button"
                        onClick={() => setMonth(i + 1)}
                        className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                            month === i + 1 ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                        }`}
                    >
                        {name.slice(0, 3)}
                    </button>
                ))}
            </div>
            <div className="mb-1 grid grid-cols-7 gap-1">
                {weekdayInitials().map((day, i) => (
                    <span key={i} className="pb-1 text-center text-xs font-medium text-gray-400">
                        {day}
                    </span>
                ))}
            </div>
            <div className="grid grid-cols-7 gap-1">{cells}</div>
            <div className="mt-2 flex flex-wrap gap-3 text-xs text-gray-500">
                {Object.entries(TYPE_COLORS).map(([type, color]) => (
                    <span key={type} className="inline-flex items-center gap-1">
                        <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: color }} />
                        {t(`hrms.holidays.${TYPE_KEYS[type]}`)}
                    </span>
                ))}
            </div>
        </div>
    );
}
