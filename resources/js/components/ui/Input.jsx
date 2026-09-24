export default function Input({ label, error, id, className = '', leadingIcon, ...props }) {
    const inputId = id || props.name;

    return (
        <div className={className}>
            {label && (
                <label htmlFor={inputId} className="mb-1.5 block text-sm font-medium text-gray-700">
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
                    className={`block w-full rounded-lg border px-3.5 py-2.5 text-sm shadow-sm transition focus:outline-none focus:ring-2 ${
                        leadingIcon ? 'pl-9' : ''
                    } ${
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