/**
 * One way to render an icon (UI/UX U1). Wraps a Phosphor component with a
 * fixed size scale and the accessibility default: decorative unless `label`
 * is given, in which case it becomes an image with an accessible name.
 *
 *   <Icon as={Bell} size="md" />               // decorative
 *   <Icon as={Warning} label="Warning" />      // announced
 */
const SIZES = { xs: 14, sm: 16, md: 20, lg: 24, xl: 32 };

export default function Icon({ as: Component, size = 'md', weight = 'regular', label, className = '', ...rest }) {
    if (!Component) return null;

    const a11y = label ? { role: 'img', 'aria-label': label } : { 'aria-hidden': true, focusable: 'false' };

    return <Component size={SIZES[size] ?? size} weight={weight} className={className} {...a11y} {...rest} />;
}

export { SIZES as ICON_SIZES };
