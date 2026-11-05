#!/usr/bin/env node
// Creates the FlowSync GitHub App through GitHub's "App manifest" flow, so nothing is typed by hand.
//
//   node scripts/github-app-manifest.mjs --url http://3.7.184.44 [--name "FlowSync"] [--port 8787]
//
// 1. Open the printed http://localhost:PORT link in a browser where you are logged in to GitHub.
// 2. Click "Create GitHub App" on GitHub. GitHub redirects back here with a one-time code.
// 3. The script exchanges the code and writes the credentials to ./github-app.generated/
//    (.env snippet + private key .pem). Those files are git-ignored: copy them to the server, never commit.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((a, v, i, all) => (v.startsWith('--') ? [...a, [v.slice(2), all[i + 1]]] : a), []));
const base = (args.url || '').replace(/\/+$/, '');
if (!/^https?:\/\//.test(base)) {
    console.error('Pass the public app URL: --url http://3.7.184.44');
    process.exit(1);
}
const port = Number(args.port || 8787);
const name = args.name || 'FlowSync';
const out = path.resolve('github-app.generated');
const state = Math.random().toString(36).slice(2);
const setup = `${base}/api/integrations/git/github/setup`;

const manifest = {
    name,
    url: base,
    hook_attributes: { url: `${base}/api/integrations/git/github/webhook`, active: true },
    redirect_url: `http://localhost:${port}/created`,
    callback_urls: [setup],
    setup_url: setup,
    setup_on_update: true,
    request_oauth_on_install: true,
    public: false,
    default_permissions: { metadata: 'read', contents: 'write', pull_requests: 'read', statuses: 'read', checks: 'read' },
    default_events: ['pull_request', 'pull_request_review', 'push', 'create', 'delete', 'release', 'check_suite', 'check_run', 'status', 'repository'],
};

const page = `<!doctype html><meta charset="utf-8"><title>Create GitHub App</title>
<body style="font:16px system-ui;max-width:40rem;margin:3rem auto">
<h1>Create the ${name} GitHub App</h1>
<p>Homepage <code>${base}</code>. Next step opens github.com.</p>
<form action="https://github.com/settings/apps/new?state=${state}" method="post">
<input type="hidden" name="manifest" id="m"><button style="font-size:1.1rem;padding:.6rem 1.2rem">Create GitHub App on GitHub</button></form>
<script>document.getElementById('m').value=${JSON.stringify(JSON.stringify(manifest))}</script></body>`;

const server = http.createServer(async (req, res) => {
    const u = new URL(req.url, `http://localhost:${port}`);
    if (u.pathname === '/') return res.writeHead(200, { 'content-type': 'text/html' }).end(page);
    if (u.pathname !== '/created' || u.searchParams.get('state') !== state) return res.writeHead(404).end('not found');
    try {
        const r = await fetch(`https://api.github.com/app-manifests/${u.searchParams.get('code')}/conversions`, {
            method: 'POST',
            headers: { accept: 'application/vnd.github+json', 'user-agent': 'flowsync-app-manifest' },
        });
        if (!r.ok) throw new Error(`GitHub answered ${r.status}: ${await r.text()}`);
        const app = await r.json();
        fs.mkdirSync(out, { recursive: true, mode: 0o700 });
        const pem = path.join(out, 'github-app.pem');
        fs.writeFileSync(pem, app.pem, { mode: 0o600 });
        fs.writeFileSync(path.join(out, 'github-app.env'), [
            'GIT_ENABLED=true',
            `GITHUB_APP_ID=${app.id}`,
            `GITHUB_APP_SLUG=${app.slug}`,
            'GITHUB_APP_PRIVATE_KEY_PATH=/run/secrets/github-app.pem',
            `GITHUB_APP_WEBHOOK_SECRET=${app.webhook_secret}`,
            `GITHUB_APP_CLIENT_ID=${app.client_id}`,
            `GITHUB_APP_CLIENT_SECRET=${app.client_secret}`,
            '',
        ].join('\n'), { mode: 0o600 });
        res.writeHead(200, { 'content-type': 'text/html' }).end(`<body style="font:16px system-ui;max-width:40rem;margin:3rem auto"><h1>Done</h1><p>App <b>${app.slug}</b> created. Credentials are in <code>${out}</code>. Now install it: <a href="${app.html_url}/installations/new">install</a>. You can close this tab.</p>`);
        console.log(`Created ${app.slug} (id ${app.id}). Credentials written to ${out}`);
    } catch (e) {
        res.writeHead(500).end(String(e.message));
        console.error(e.message);
    }
    setTimeout(() => server.close(), 500);
});
server.listen(port, '127.0.0.1', () => console.log(`Open http://localhost:${port}/ in your browser (logged in to GitHub).`));
