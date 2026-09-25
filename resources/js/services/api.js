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
