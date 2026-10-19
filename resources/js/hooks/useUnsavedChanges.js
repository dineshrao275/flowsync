import { useCallback, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useConfirm } from '../components/ui/ConfirmDialog';

/**
 * Guard against silently losing edits.
 *
 *  - While `dirty`, the browser's own "leave site?" prompt covers reload, tab
 *    close and typing a new URL.
 *  - For in-app exits the caller asks first: `if (await confirmDiscard()) onClose()`.
 *    It resolves `true` immediately when nothing is dirty.
 *
 * The app mounts a classic `<BrowserRouter>`, which has no navigation blocker
 * (`useBlocker` needs a data router), so route changes through links are not
 * intercepted — drawers/modals, where discarding is one stray click or Esc
 * away, are the guarded surface.
 */
export default function useUnsavedChanges(dirty) {
    const { t } = useTranslation();
    const confirm = useConfirm();

    useEffect(() => {
        if (!dirty) return undefined;

        const onBeforeUnload = (event) => {
            event.preventDefault();
            // Required by older Chromium to show the prompt.
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);

    return useCallback(
        () => (dirty
            ? confirm({
                title: t('admin.common.unsavedTitle'),
                message: t('admin.common.unsavedMessage'),
                confirmLabel: t('admin.common.discard'),
                cancelLabel: t('admin.common.keepEditing'),
                variant: 'danger',
            })
            : Promise.resolve(true)),
        [dirty, confirm, t],
    );
}
