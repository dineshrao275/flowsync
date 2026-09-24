const palette = {
    admin: 'bg-indigo-100 text-indigo-700',
    editor: 'bg-amber-100 text-amber-700',
    viewer: 'bg-sky-100 text-sky-700',
};

export default function Badge({ children }) {
    const key = String(children).toLowerCase();

    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                palette[key] || 'bg-gray-100 text-gray-700'
            }`}
        >
            {children}
        </span>
    );
}
