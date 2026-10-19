import Avatar from './Avatar';

/** A person inline: circular avatar (photo or initials) followed by their name. */
export default function UserChip({ user, name, size = 'xs', className = '', nameClassName = '' }) {
    const label = name ?? user?.name;

    return (
        <span className={`inline-flex min-w-0 items-center gap-1.5 ${className}`}>
            <Avatar name={label} photoUrl={user?.photo_url} size={size} />
            <span className={`truncate ${nameClassName}`}>{label}</span>
        </span>
    );
}
