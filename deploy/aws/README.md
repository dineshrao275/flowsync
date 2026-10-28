# Deploying FlowSync to AWS (single EC2 host)

One EC2 instance runs the whole stack with Docker Compose: Caddy (port 80), the app, queue worker,
Reverb (WebSockets), the scheduler and PostgreSQL. State lives on the instance's encrypted EBS volume
(`pgdata` and `storage` Docker volumes). No high availability: a host failure means restoring from backup.

| File | Purpose |
|---|---|
| `provision.sh` | AWS side: scoped IAM user, key pair, security group, instance, Elastic IP |
| `deploy.sh` | Sync source, render secrets, build and start the stack on the instance |
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
