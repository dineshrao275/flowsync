import { useState } from 'react';

/** "Ada Lovelace" -> "AL", "Ada" -> "A": first letters of the first and last name. */
export function initialsOf(name) {
    const words = (name ?? '').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return '?';
    const first = words[0].charAt(0);
    const last = words.length > 1 ? words[words.length - 1].charAt(0) : '';

    return (first + last).toUpperCase();
}

const SIZES = {
    xs: 'h-6 w-6 text-[0.7rem]',
    sm: 'h-7 w-7 text-xs',
    md: 'h-8 w-8 text-sm',
    lg: 'h-9 w-9 text-sm',
    xl: 'h-11 w-11 text-base',
};

/** Circular user icon: the profile photo when there is one (and it loads), otherwise initials. */
export default function Avatar({ name, photoUrl = null, size = 'md', className = '' }) {
    const [broken, setBroken] = useState(false);
    const showPhoto = photoUrl && !broken;

    return (
        <span
            className={`inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-semibold text-white ${SIZES[size] ?? SIZES.md} ${className}`}
            style={{ backgroundColor: 'var(--accent)' }}
            title={name}
        >
            {showPhoto ? (
                <img src={photoUrl} alt="" className="h-full w-full object-cover" onError={() => setBroken(true)} />
            ) : (
                initialsOf(name)
            )}
        </span>
    );
}
