import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
    withXSRFToken: true,
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401 && !error.config?.url?.includes('/auth/')) {
            if (!window.location.pathname.startsWith('/app/login')) {
                window.location.href = '/app/login';
            }
        }

        // A page-load GET that the server denies because the plan lacks the
        // module surfaces the dedicated "not included in your plan" page — the
        // generic 403 page would mislabel a plan limitation as a permission.
        // Mutations keep their inline fieldErrors (the server message travels
        // in the body, the theme flow already renders it).
        if (
            error.response?.status === 403 &&
            error.response?.headers?.['x-module-denied'] &&
            error.config?.method === 'get' &&
            !window.location.pathname.startsWith('/app/module-denied')
        ) {
            window.location.href = '/app/module-denied';
        }

        // When onboarding is incomplete, redirect the user back to the onboarding wizard
        if (
            error.response?.status === 403 &&
            (error.response?.headers?.['x-onboarding-redirect'] || error.response?.data?.redirect === '/onboarding') &&
            !window.location.pathname.startsWith('/app/onboarding')
        ) {
            window.location.href = '/app/onboarding';
        }

        return Promise.reject(error);
    },
);

export function fieldErrors(error) {
    const errors = {};
    const payload = error?.response?.data;

    if (payload?.errors) {
        Object.entries(payload.errors).forEach(([key, messages]) => {
            errors[key] = Array.isArray(messages) ? messages[0] : messages;
        });
    } else if (payload?.message) {
        errors.form = payload.message;
    } else {
        errors.form = 'Something went wrong. Please try again.';
    }

    return errors;
}

export default api;
