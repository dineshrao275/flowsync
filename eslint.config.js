import js from '@eslint/js';
import globals from 'globals';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';

export default [
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'bootstrap/cache/**'] },
    js.configs.recommended,
    { linterOptions: { reportUnusedDisableDirectives: 'off' } },
    {
        files: ['resources/js/**/*.{js,jsx}', 'vitest.config.js', 'vite.config.js'],
        plugins: { react, 'react-hooks': reactHooks },
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: { ecmaFeatures: { jsx: true } },
            globals: { ...globals.browser, ...globals.node },
        },
        rules: {
            // JSX usage counts as a use (otherwise every component import is "unused").
            'react/jsx-uses-vars': 'error',
            'react/jsx-uses-react': 'off',
            'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_', caughtErrors: 'none' }],
            'react-hooks/rules-of-hooks': 'error',
        },
    },
    {
        files: ['resources/js/**/*.test.{js,jsx}', 'resources/js/test/**'],
        languageOptions: { globals: { ...globals.vitest } },
    },
];
