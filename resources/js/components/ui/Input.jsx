import DatePicker from 'react-datepicker';
import 'react-datepicker/dist/react-datepicker.css';
import { format } from 'date-fns';

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

/**
 * Every date input in the app flows through here, so `type="date"` (and
 * `datetime-local`) renders a real calendar popup instead of the native
 * control — which varies by browser/OS and never opens a proper calendar
 * on click in some of them. Values stay plain strings
 * (`YYYY-MM-DD`, datetimes as `YYYY-MM-DDTHH:mm`), and onChange keeps the
 * `{ target: { value, name } }` shape, so all ~50 call sites work
 * unchanged. Native `time`/`month` inputs are left alone.
 */
function DateField({ label, labelClassName = '', error, id, className = '', leadingIcon, compact = false, ...props }) {
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

    return (
        <div className={className}>
            {label && (
                <label htmlFor={inputId} className={`mb-1.5 block text-sm font-medium text-gray-700 ${labelClassName}`}>
                    {label}
                </label>
            )}
            <div className="relative">
                {leadingIcon && (
                    <span className="pointer-events-none absolute left-3 top-1/2 z-10 -translate-y-1/2 text-gray-400">
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
                    placeholderText={props.placeholder}
                    disabled={props.disabled}
                    required={props.required}
                    autoComplete="off"
                    aria-label={props['aria-label']}
                    isClearable={!props.required && !props.disabled && !!props.value}
                    showPopperArrow={false}
                    calendarClassName="flowsync-calendar"
                    className={`block w-full border text-sm shadow-sm transition focus:outline-none focus:ring-2 ${
                        compact ? 'rounded-md px-2.5 py-1.5' : 'rounded-lg px-3.5 py-2.5'
                    } ${leadingIcon ? 'pl-9' : ''} ${
                        error
                            ? 'border-red-400 focus:border-red-500 focus:ring-red-100'
                            : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-100'
                    } ${props.disabled ? 'cursor-not-allowed bg-gray-50 text-gray-500' : 'bg-white text-gray-900'}`}
                />
            </div>
            {error && <p className="mt-1.5 text-sm text-red-600">{error}</p>}
        </div>
    );
}

export default function Input({ label, labelClassName = '', error, id, className = '', leadingIcon, compact = false, ...props }) {
    if (props.type === 'date' || props.type === 'datetime-local') {
        return <DateField label={label} labelClassName={labelClassName} error={error} id={id} className={className} leadingIcon={leadingIcon} compact={compact} {...props} />;
    }

    const inputId = id || props.name;

    return (
        <div className={className}>
            {label && (
                <label htmlFor={inputId} className={`mb-1.5 block text-sm font-medium text-gray-700 ${labelClassName}`}>
                    {label}
                </label>
            )}
            <div className="relative">
                {leadingIcon && (
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                        {leadingIcon}
                    </span>
                )}
                <input
                    id={inputId}
                    className={`block w-full border text-sm shadow-sm transition focus:outline-none focus:ring-2 ${
                        compact ? 'rounded-md px-2.5 py-1.5' : 'rounded-lg px-3.5 py-2.5'
                    } ${leadingIcon ? 'pl-9' : ''} ${
                        error
                            ? 'border-red-400 focus:border-red-500 focus:ring-red-100'
                            : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-100'
                    } ${props.disabled ? 'cursor-not-allowed bg-gray-50 text-gray-500' : 'bg-white text-gray-900'}`}
                    {...props}
                />
            </div>
            {error && <p className="mt-1.5 text-sm text-red-600">{error}</p>}
        </div>
    );
}
