/**
 * One status pill for every surface: a hue dot on a tinted chip with dark
 * text. The text is deliberately NOT the hue — 12px colored text on its
 * own tint fails contrast almost everywhere (amber-on-amber is ~2:1) —
 * while the dot and the tint keep the color coding. Replaces the three
 * per-file pills (Checklist, DocumentList, RequestPill) so the next
 * status color is fixed once, not three times.
 */
export default function StatusPill({ color = '#6b7280', label, className = '' }) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium capitalize ${className}`}
            style={{ backgroundColor: `${color}22` }}
        >
            <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ backgroundColor: color }} />
            <span className="text-gray-800">{label}</span>
        </span>
    );
}
