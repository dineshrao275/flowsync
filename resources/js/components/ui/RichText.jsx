import DOMPurify from 'dompurify';

/**
 * Plain-text excerpt of editor HTML, for truncated single-line cells
 * (table rows, cards) where block markup would break the layout.
 */
export function stripRichText(html) {
    if (!html) return '';
    return new DOMParser().parseFromString(html, 'text/html').body.textContent || '';
}

/**
 * Read-only rich text: renders editor HTML with scripts, event handlers
 * and unsafe markup stripped. Plain-text descriptions (written before the
 * editor existed) pass through untouched — a string with no tags renders
 * as its own text.
 */
export default function RichText({ value, className = '' }) {
    if (!value) return null;

    return (
        <div
            className={`rich-text text-sm text-gray-800 ${className}`}
            dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(value) }}
        />
    );
}
