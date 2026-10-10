import DatePicker from 'react-datepicker';
import 'react-datepicker/dist/react-datepicker.css';
import { format } from 'date-fns';
import { fieldClass, fieldClassCompact } from './fieldStyles';

function parseDate(value) {
    if (!value || typeof value !== 'string') return null;
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim());
    if (!match) return null;
    // Constructed from parts (never `new Date('YYYY-MM-DD')`) so the day
    // never shifts a timezone away from what the server sent.
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return Number.isNaN(date.getTime()) ? null : date;
}

function parseDateTime(value) {
    if (!value || typeof value !== 'string') return null;
    const date = new Date(value.includes('T') ? value : value.replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? null : date;
}

const labelClass = 'mb-1.5 block text-[12px] font-semibold text-ink';

/**
 * Every date input in the app flows through here, so `type="date"` (and
 * `datetime-local`) renders a real calendar popup instead of the native
 * control — which varies by browser/OS and never opens a proper calendar
 * on click in some of them. Values stay plain strings
 * (`YYYY-MM-DD`, datetimes as `YYYY-MM-DDTHH:mm`), and onChange keeps the
 * `{ target: { value, name } }` shape, so all ~50 call sites work
 * unchanged. Native `time`/`month` inputs are left alone.
 */
function DateField({ label, labelClassName = '', error, id, className = '', leadingIcon, compact = false, placeholder, ...props }) {
    const inputId = id || props.name;
    const withTime = props.type === 'datetime-local';
    const selected = withTime ? parseDateTime(props.value) : parseDate(props.value);

    const emit = (date) => {
        props.onChange?.({
            target: {
                value: date ? format(date, withTime ? "yyyy-MM-dd'T'HH:mm" : 'yyyy-MM-dd') : '',
                name: props.name,
            },
        });
    };

    const base = compact ? fieldClassCompact : fieldClass;
    const errorClass = 'border-[var(--danger)] focus:border-[var(--danger)] focus:ring-[var(--danger-ring)]/50';

    return (
        <div className={className}>
            {label && (
                <label htmlFor={inputId} className={`${labelClass} ${labelClassName}`}>
                    {label}
                </label>
            )}
            <div className="relative">
                {leadingIcon && (
                    <span className="pointer-events-none absolute left-3 top-1/2 z-10 -translate-y-1/2 text-[var(--text-faint)]">
                        {leadingIcon}
                    </span>
                )}
                <DatePicker
                    id={inputId}
                    selected={selected}
                    onChange={emit}
                    onBlur={props.onBlur}
                    dateFormat={withTime ? 'yyyy-MM-dd HH:mm' : 'yyyy-MM-dd'}
                    showTimeSelect={withTime}
                    timeIntervals={15}
                    minDate={props.min ? parseDate(props.min) ?? undefined : undefined}
                    maxDate={props.max ? parseDate(props.max) ?? undefined : undefined}
                    placeholderText={placeholder ?? (withTime ? 'Select date & time…' : 'Select date…')}
                    disabled={props.disabled}
                    required={props.required}
                    autoComplete="off"
                    aria-label={props['aria-label']}
                    isClearable={!props.required && !props.disabled && !!props.value}
                    showPopperArrow={false}
                    calendarClassName="flowsync-calendar"
                    className={`${base} ${leadingIcon ? 'pl-9' : ''} ${error ? errorClass : ''} ${props.disabled ? 'cursor-not-allowed opacity-60' : ''}`}
                />
            </div>
            {error && <p className="mt-1.5 text-[12px] text-[var(--danger)]">{error}</p>}
        </div>
    );
}

export default function Input({ label, labelClassName = '', error, id, className = '', leadingIcon, compact = false, placeholder, ...props }) {
    // Every input shows a hint: explicit prop first, then the label text.
    // (Labels stay the accessible name; the placeholder is a visual echo.)
    const hint = placeholder ?? label;
    if (props.type === 'date' || props.type === 'datetime-local') {
        return <DateField label={label} labelClassName={labelClassName} error={error} id={id} className={className} leadingIcon={leadingIcon} compact={compact} placeholder={hint} {...props} />;
    }

    const inputId = id || props.name;
    const base = compact ? fieldClassCompact : fieldClass;
    const errorClass = 'border-[var(--danger)] focus:border-[var(--danger)] focus:ring-[var(--danger-ring)]/50';

    return (
        <div className={className}>
            {label && (
                <label htmlFor={inputId} className={`${labelClass} ${labelClassName}`}>
                    {label}
                </label>
            )}
            <div className="relative">
                {leadingIcon && (
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-faint)]">
                        {leadingIcon}
                    </span>
                )}
                <input
                    id={inputId}
                    placeholder={typeof hint === 'string' ? hint : undefined}
                    className={`${base} ${leadingIcon ? 'pl-9' : ''} ${error ? errorClass : ''} ${props.disabled ? 'cursor-not-allowed opacity-60' : ''}`}
                    {...props}
                />
            </div>
            {error && <p className="mt-1.5 text-[12px] text-[var(--danger)]">{error}</p>}
        </div>
    );
}
