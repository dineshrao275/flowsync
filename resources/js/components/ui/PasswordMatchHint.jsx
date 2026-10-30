import { useTranslation } from 'react-i18next';
import { CheckCircle, XCircle } from '@phosphor-icons/react';
import Icon from './Icon';

/**
 * Live "passwords match" sign for a confirm-password field. Stays hidden until
 * the confirmation has content, then announces the result politely.
 */
export default function PasswordMatchHint({ password = '', confirmation = '', className = '' }) {
    const { t } = useTranslation();

    if (!confirmation) return null;

    const matches = password === confirmation;

    return (
        <p
            role="status"
            aria-live="polite"
            className={`flex items-center gap-1.5 text-sm ${matches ? 'text-success' : 'text-danger-fg'} ${className}`}
        >
            <Icon as={matches ? CheckCircle : XCircle} size="sm" weight="fill" />
            {t(matches ? 'ui.passwordsMatch' : 'ui.passwordsMismatch')}
        </p>
    );
}
