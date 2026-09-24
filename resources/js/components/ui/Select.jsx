import { fieldClass } from './fieldStyles';

export default function Select({ label, error, id, className = '', children, ...props }) {
    const selectId = id || props.name;

    return (
        <div className={className}>
            {label && (
                <label htmlFor={selectId} className="mb-1.5 block text-sm font-medium text-gray-700">
                    {label}
                </label>
            )}
            <select
                id={selectId}
                className={`${fieldClass} ${error ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : ''}`}
                {...props}
            >
                {children}
            </select>
            {error && <p className="mt-1.5 text-sm text-red-600">{error}</p>}
        </div>
    );
}