import js from '@eslint/js';
import globals from 'globals';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';

export default [
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'bootstrap/cache/**'] },
    js.configs.recommended,
    { linterOptions: { reportUnusedDisableDirectives: 'warn' } },
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
            // Stale-closure guard. Warn while the existing disables are worked off
            // (docs/coding-standards.md section 8); promote to error at zero.
            'react-hooks/exhaustive-deps': 'warn',
            // Raw <table> markup skips the scroll wrapper, header semantics and
            // density of ui/Table (UI/UX U2/U6). Warn while the stragglers migrate.
            // window.confirm must go through useConfirm(); raw <select> through ui/Select.
            'no-restricted-syntax': ['warn', {
                selector: "JSXOpeningElement[name.name='table']",
                message: 'Use the Table / DataTable primitives from components/ui instead of a raw <table>.',
            }, {
                selector: "CallExpression[callee.object.name='window'][callee.property.name='confirm']",
                message: 'Use useConfirm() from components/ui/ConfirmDialog instead of window.confirm.',
            }, {
                selector: "JSXOpeningElement[name.name='select']",
                message: 'Use the Select primitive from components/ui (inline cells may use fieldClass).',
            }],
        },
    },
    { files: ['resources/js/components/ui/Table.jsx'], rules: { 'no-restricted-syntax': 'off' } },
    {
        files: ['resources/js/**/*.test.{js,jsx}', 'resources/js/test/**'],
        languageOptions: { globals: { ...globals.vitest } },
    },
];
