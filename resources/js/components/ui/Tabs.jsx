/**
 * Shared underline tab bar. Was duplicated verbatim in ProjectDetail and
 * WorkspaceDetail, so the markup lives here once.
 *
 * `tabs` is an array of `{ key, label }`; `label` may already carry a count
 * (e.g. `Members (3)`). `active` is the current key, `onChange(key)` fires on
 * click. Behaviour is identical to the two per-page copies it replaces.
 */
export default function Tabs({ tabs, active, onChange }) {
    return (
        <div className="flex gap-1 border-b border-gray-200">
            {tabs.map((tab) => (
                <button
                    key={tab.key}
                    type="button"
                    onClick={() => onChange(tab.key)}
                    className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition ${active === tab.key
                            ? 'border-indigo-600 text-indigo-600'
                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                        }`}
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}
