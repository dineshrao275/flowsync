# Deploying FlowSync to AWS (single EC2 host)

One EC2 instance runs the whole stack with Docker Compose: Caddy (port 80), the app, queue worker,
Reverb (WebSockets), the scheduler and PostgreSQL. State lives on the instance's encrypted EBS volume
(`pgdata` and `storage` Docker volumes). No high availability: a host failure means restoring from backup.

| File | Purpose |
|---|---|
| `provision.sh` | AWS side: scoped IAM user, key pair, security group, instance, Elastic IP |
| `deploy.sh` | Manual deploy from a workstation: sync source, render secrets, build and start the stack |
| `setup-cicd.sh` | One-time: release bucket, SSM instance profile, GitHub OIDC role (admin credentials) |
| `release.sh` | CI entry point: package a commit, upload to S3, deploy through SSM, wait for the result |
| `remote-deploy.sh` | Runs on the instance: backup, swap, rebuild, health check, automatic rollback |
| `docker-compose.prod.yml` | Production services (no bind mounts; only the proxy is published) |
| `Caddyfile` | `:80` reverse proxy; only a real WebSocket upgrade on `/app/*` goes to Reverb |
| `env.production.template` | Rendered once into `~/.flowsync-aws/env.production` with generated secrets |

## First deployment
```bash
AWS_PROFILE=<admin>  deploy/aws/provision.sh iam     # once: creates IAM user flowsync-deployer + profile flowsync-deploy
deploy/aws/provision.sh infra                         # instance + Elastic IP (default c7i-flex.large, 40 GB, ap-south-1)
deploy/aws/deploy.sh                                  # build + start; prints the URL
```
Instance type: `c7i-flex.large` (2 vCPU / 4 GB) is the smallest Free-Plan-eligible type that fits the
stack; override with `INSTANCE_TYPE=...`. Region with `AWS_REGION=...`.

## Continuous deployment (GitHub Actions)
Merging to `master` runs the existing **CI** workflow; when it passes, **Deploy to AWS**
(`.github/workflows/deploy.yml`) ships that exact commit. A manual run (Actions -> Deploy to AWS ->
Run workflow, on `master`) redeploys without waiting for CI.

```
merge -> CI passes -> deploy.yml -> release.sh: tar the commit -> S3 (7-day expiry) -> SSM Run Command
      -> on the instance, remote-deploy.sh: dump all databases -> keep previous image + source ->
         swap source -> docker compose up --build -> wait for /up -> (on failure: automatic rollback)
```
- **No stored secrets.** GitHub proves its identity to AWS with OIDC; the role `flowsync-github-deploy` only trusts
  this repository's `master` branch and may only: upload to the release bucket, run one SSM document on this one
  instance, and read the result. The instance downloads the release with a 15-minute presigned URL.
- **One-time setup** (needs admin credentials because it creates IAM resources):
  `AWS_PROFILE=<admin> deploy/aws/setup-cicd.sh`. It is idempotent and prints the four values that must match the `env:` block in `deploy.yml`.
- **Database changes.** A new app container runs system migrations and `tenants:provision` on start (`docker/entrypoint.sh`),
  so migrations apply during the deploy. A pre-deploy `pg_dumpall` is kept in `/opt/backups` (last 5). A rollback restores the
  previous code and image, **not** the database: a destructive migration needs a manual restore from that dump.
- **Downtime.** The app container is recreated, so expect roughly a minute of errors while it restarts.
- **Tested.** `remote-deploy.sh` was run on the live host with an identical-content release (full path) and with a release whose
  container crashes on start (it rolled back automatically and exited 1). The OIDC/SSM leg runs for the first time on the first merge.

## Day-2
- Redeploy: `deploy/aws/deploy.sh` (idempotent; keeps secrets and data).
- Logs: `deploy/aws/deploy.sh logs app` (or `queue`, `reverb`, `scheduler`, `proxy`, `db`).
- Shell/command: `deploy/aws/deploy.sh ssh 'sudo docker ps'`.
- Tear down: `deploy/aws/provision.sh destroy` (data on the instance is lost; take a backup first).

## Secrets and first login
`~/.flowsync-aws/env.production` (mode 600, never in git) holds `APP_KEY`, DB and Reverb secrets and the
first platform admin (`SUPERADMIN_EMAIL` / `SUPERADMIN_PASSWORD`). `ProductionSeeder` creates only that
admin, the plans, platform settings and public-site content: no demo tenants or default passwords.
**Change the admin password after the first login** and keep a copy of `APP_KEY` (losing it makes
encrypted tenant credentials unreadable).

## Not configured yet (decisions for later)
- **HTTPS / domain.** Plain HTTP on the Elastic IP. To add a domain: point DNS at the IP, open 443 in the
  security group, replace `:80` in the Caddyfile with the hostname (Caddy fetches the certificate), set
  `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true`, `REVERB_SCHEME=https`, `REVERB_PORT=443`, and
  rebuild with `PUBLIC_HOST` set to the hostname and `VITE_REVERB_PORT=443` / scheme `https`.
- **Backups.** `php artisan tenants:backup --all` writes snapshots under `storage/app/backups`
  inside the container; copy them off the host (S3) and/or snapshot the EBS volume on a schedule.
- **Email and payments.** `MAIL_MAILER=log` and Stripe in mock mode until real credentials are added to
  `.env.production` on the server (then `deploy.sh` or `docker compose up -d`).
- **Monitoring.** Add a CloudWatch alarm on instance status checks and a billing budget.
