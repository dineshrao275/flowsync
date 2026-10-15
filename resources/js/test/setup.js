import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';
// Every component test renders against the real dictionaries: migrated copy
// resolves to English, unmigrated keys degrade to visible keys (never blanks).
import '../i18n';
import i18n, { DEFAULT_LOCALE } from '../i18n';

afterEach(() => cleanup());

afterEach(async () => {
    window.localStorage.removeItem('flowsync.locale');
    await i18n.changeLanguage(DEFAULT_LOCALE);
});
