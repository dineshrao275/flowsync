// F11.6 — the everyday read mix of a task-management user: dashboard, project list, a board, search.
import { sleep } from 'k6';
import { login, get } from './common.js';

export const options = {
    scenarios: {
        steady: { executor: 'constant-vus', vus: Number(__ENV.VUS || 20), duration: __ENV.DURATION || '2m' },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{name:board}': ['p(95)<800'],
        'http_req_duration{name:dashboard}': ['p(95)<800'],
        'http_req_duration{name:search}': ['p(95)<600'],
    },
};

export function setup() {
    return {};
}

export default function () {
    const headers = login();
    get('/dashboard', headers, 'dashboard');
    const projects = get('/projects', headers, 'projects').json('projects') || [];
    if (projects.length) {
        const id = projects[Math.floor(Math.random() * projects.length)].id;
        get(`/projects/${id}/tasks`, headers, 'board');
        get(`/projects/${id}`, headers, 'project');
    }
    get('/search/global?q=task', headers, 'search');
    get('/notifications/unread', headers, 'notifications');
    sleep(1 + Math.random() * 2);
}
