import axios from 'axios';
import logger from '../utils/logger';

const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
    withXSRFToken: true,
});

api.interceptors.request.use((config) => {
    config.metadata = { startTime: Date.now() };
    logger.debug('API', `${config.method?.toUpperCase()} ${config.url}`, {
        params: config.params,
    });
    return config;
});

api.interceptors.response.use(
    (response) => {
        const duration = response.config?.metadata?.startTime
            ? Date.now() - response.config.metadata.startTime
            : null;
        logger.debug('API', `${response.status} ${response.config?.method?.toUpperCase()} ${response.config?.url} (${duration}ms)`);
        return response;
    },
    (error) => {
        const duration = error.config?.metadata?.startTime
            ? Date.now() - error.config.metadata.startTime
            : null;
        const status = error.response?.status;
        const method = error.config?.method?.toUpperCase();
        const url = error.config?.url;

        logger.error('API', `${status || 'NETWORK_ERR'} ${method} ${url} (${duration}ms)`, {
            status,
            message: error.response?.data?.message || error.message,
            errors: error.response?.data?.errors,
            requestId: error.response?.headers?.['x-request-id'],
        });

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
