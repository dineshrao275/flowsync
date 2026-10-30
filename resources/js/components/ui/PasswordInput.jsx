import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Eye, EyeSlash } from '@phosphor-icons/react';
import Icon from './Icon';
import Input from './Input';

/**
 * Password field with a show/hide toggle. Keeps the `Input` contract
 * (label/name/value/onChange/error), so call sites only swap the component.
 */
export default function PasswordInput({ id, name, disabled, ...props }) {
    const { t } = useTranslation();
    const [visible, setVisible] = useState(false);

    return (
        <Input
            {...props}
            id={id}
            name={name}
            disabled={disabled}
            type={visible ? 'text' : 'password'}
            trailingIcon={
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => setVisible((current) => !current)}
                    aria-label={t(visible ? 'ui.hidePassword' : 'ui.showPassword')}
                    aria-pressed={visible}
                    aria-controls={id || name || undefined}
                    className="rounded-md p-1.5 text-gray-400 transition hover:text-gray-600 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent)] disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <Icon as={visible ? EyeSlash : Eye} size="sm" />
                </button>
            }
        />
    );
}
