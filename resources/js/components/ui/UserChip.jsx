import Avatar from './Avatar';
import UserTooltip from './UserTooltip';

/**
 * A person inline: circular avatar (photo or initials) followed by their name.
 * Equipped with context-aware UserTooltip and profile navigation.
 */
export default function UserChip({
    user,
    name,
    size = 'xs',
    className = '',
    nameClassName = '',
    tooltip = true,
    clickable = true,
    placement = 'top',
}) {
    const label = name ?? user?.name;

    const chip = (
        <span className={`inline-flex min-w-0 items-center gap-1.5 transition-opacity hover:opacity-90 ${className}`}>
            <Avatar name={label} photoUrl={user?.photo_url} size={size} />
            <span className={`truncate ${nameClassName}`}>{label}</span>
        </span>
    );

    if (!tooltip) {
        return chip;
    }

    return (
        <UserTooltip user={user} name={label} placement={placement} clickable={clickable}>
            {chip}
        </UserTooltip>
    );
}

