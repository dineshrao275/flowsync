# FlowSync — Operations & Production Runbook

This runbook documents the deployment, operations, monitoring, backup/restore, and maintenance procedures for the FlowSync multi-tenant platform.

---

## 1. Architecture & Topology Overview

FlowSync uses a **physically isolated multi-tenancy model (Phase 13+)**:
- **Central System Database (`system`)**: Holds global metadata (`tenants`, `tenant_users`, `subscription_plans`, `subscriptions`, `usage_metrics`, `audit_logs`, `payments`, `payment_events`, central sessions, and platform super admins).
- **Per-Tenant Databases (`tenant`)**: Each tenant operates inside an isolated database without `tenant_id` columns (PostgreSQL in production/host, SQLite file per tenant in local/test).
- **Application Services**:
  - Web Server: Apache `mod_php` or Nginx + PHP-FPM 8.3 serving `public/`.
  - Frontend: React 19 SPA built via Vite 7 (`public/build`).
  - WebSockets: Laravel Reverb (`php artisan reverb:start`) on port 8080.
  - Background Queue: Database queue worker (`php artisan queue:work`).

---

## 2. Production Deployment Checklist

### 2.1 Server Prerequisites
- **OS**: Ubuntu 22.04 LTS / Debian 12 / Linux
- **PHP**: 8.3 CLI + FPM/mod_php with extensions:
  `php8.3-pgsql`, `php8.3-sqlite3`, `php8.3-bcmath`, `php8.3-mbstring`, `php8.3-curl`, `php8.3-xml`, `php8.3-zip`, `php8.3-pcntl`, `php8.3-redis`
- **Database**: PostgreSQL 16+
- **Node.js**: v20+ & npm

### 2.2 Environment Configuration (`.env`)
Ensure the following settings are enforced for production:
```ini
APP_NAME=FlowSync
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_KEY=base64:...

# Tenancy Configuration (PostgreSQL Dedicated)
DB_CONNECTION=pgsql
DB_SYSTEM_DATABASE=flowsync_system
DB_HOST=127.0.0.1
DB_PORT=5432
DB_USERNAME=flowsync
DB_PASSWORD=your_strong_password

TENANCY_DRIVER=isolated
TENANT_DB_DRIVER=pgsql
ENABLE_TRGM=true

# Central Infrastructure Stores
SESSION_DRIVER=database
SESSION_CONNECTION=system
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
DB_QUEUE_CONNECTION=system
CACHE_STORE=database
DB_CACHE_CONNECTION=system

# Reverb WebSockets
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=your_reverb_id
REVERB_APP_KEY=your_reverb_key
REVERB_APP_SECRET=your_reverb_secret
REVERB_HOST="your-domain.com"
REVERB_PORT=443
REVERB_SCHEME=https
```

### 2.3 Bootstrap Commands (Deploy Pipeline)
```bash
# 1. Install production dependencies
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 2. Run central system migrations
php artisan migrate --database=system --path=database/migrations/system --force

# 3. Provision or update tenant databases and catalogs
php artisan tenants:provision

# 4. Cache configurations and routes
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Restart queue workers
php artisan queue:restart
```

### 2.4 Systemd Services for Background Workers
Configure user or system systemd units for continuous background operation:

**`flowsync-queue.service`**:
```ini
[Unit]
Description=FlowSync Background Queue Worker
After=network.target postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/flowsync
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

**`flowsync-reverb.service`**:
```ini
[Unit]
Description=FlowSync Reverb WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/flowsync
ExecStart=/usr/bin/php artisan reverb:start --port=8080 --host=0.0.0.0
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

---

## 3. Mail Transport Configuration (Free Tier ~5,000 Emails/Month)

FlowSync supports standard SMTP transports. For free-tier production mail delivery, any of the following providers may be configured:

1. **Brevo (formerly Sendinblue)**:
   - Free tier: **300 emails/day (~9,000 emails/month)**.
   - Host: `smtp-relay.brevo.com`
   - Port: `587`
   - Encryption: TLS
2. **Resend**:
   - Free tier: **3,000 emails/month (100/day)**.
   - Host: `smtp.resend.com`
   - Port: `587`
3. **MailerSend**:
   - Free tier: **3,000 emails/month**.
   - Host: `smtp.mailersend.net`
   - Port: `587`

### Production `.env` Setup:
```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=your_registered_email@domain.com
MAIL_PASSWORD=your_smtp_api_key
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=notifications@your-domain.com
MAIL_FROM_NAME="FlowSync"
```

### Verification:
Test sending via CLI:
```bash
php artisan tinker --execute="Mail::raw('FlowSync test email', fn(\$m) => \$m->to('admin@your-domain.com')->subject('Mail Transport Verification'));"
```

---

## 4. Billing & Gateways Setup (Stripe & Razorpay)

FlowSync routes checkouts and subscriptions dynamically based on tenant country and currency:
- **INR currency / India locale**: Handled by **Razorpay**.
- **USD / EUR / GBP / International**: Handled by **Stripe**.

### 4.1 Production Credentials Configuration
Set the live production keys in `.env`:
```ini
PAYMENT_CURRENCY=usd
PAYMENT_DRIVER=auto

# Live Stripe Credentials
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...

# Live Razorpay Credentials
RAZORPAY_KEY=rzp_live_...
RAZORPAY_SECRET=your_razorpay_secret
RAZORPAY_WEBHOOK_SECRET=your_razorpay_webhook_secret
```

### 4.2 Webhook Registration
Register the following endpoints in the respective developer dashboards:
- **Stripe Dashboard**:
  - Webhook URL: `https://your-domain.com/api/webhooks/stripe`
  - Events: `checkout.session.completed`, `payment_intent.succeeded`, `payment_intent.payment_failed`
- **Razorpay Dashboard**:
  - Webhook URL: `https://your-domain.com/api/webhooks/razorpay`
  - Events: `order.paid`, `payment.captured`, `payment.failed`

---

## 5. Automated Database Backups & Restore Drills

FlowSync provides an enterprise backup and integrity verification command (`tenants:backup`) supporting both central and tenant databases.

### 5.1 Manual Backup
```bash
# Backup system DB and all active tenant DBs with checksum verification
php artisan tenants:backup --all --verify

# Backup a specific tenant only
php artisan tenants:backup --tenant=acme --verify
```

Backups are saved to timestamped directories under `storage/app/backups/YYYY-MM-DD_HHMMSS/` containing:
- `system.sql` (or `system.sqlite`)
- `tenant_{slug}_{id}.sql` (or `.sqlite`)
- `manifest.json` (SHA-256 checksums, byte sizes, and timestamps)

### 5.2 Automated Daily Backups
The automated backup is scheduled in `routes/console.php` to run daily at 02:00 UTC:
```bash
# Handled automatically by Laravel Scheduler:
php artisan schedule:run
```

### 5.3 Non-Destructive Restore Drill
To verify backup viability without modifying or risking production data:
```bash
# Test the latest backup
php artisan tenants:backup --restore-drill=latest

# Test a specific manifest
php artisan tenants:backup --restore-drill=storage/app/backups/2026-10-08_180000/manifest.json
```
The drill performs:
1. Full SHA-256 integrity verification against the manifest.
2. Read-test validation confirming records can be queried cleanly.

---

## 6. Health Monitoring & Observability

### 6.1 Health Endpoints
- **Public Uptime Probe**:
  `GET /api/health`
  Returns HTTP 200 `{"status": "healthy"}` or HTTP 503 if system database connectivity fails.
- **Super Admin Detailed Health Report**:
  `GET /api/platform/health` (also aliased at `GET /api/system/health`)
  Gated by super admin session. Returns:
  - System database latency (ms)
  - Sampled tenant databases connectivity & latency (ms)
  - Cache store read/write status
  - Filesystem disk writability
  - Queue connection status

### 6.2 Tenant Usage Metrics Collection
A daily job gathers cross-tenant statistics into the central `usage_metrics` table:
```bash
php artisan tenants:collect-usage
```

### 6.3 Log Files
- Main Application: `storage/logs/laravel.log`
- Queue Worker: `storage/logs/queue-worker.log`
- Reverb WebSockets: `storage/logs/reverb.log`

---

## 7. Emergency & Disaster Recovery Procedures

### 7.1 Restoring Central System Database
```bash
# In PostgreSQL:
psql -h 127.0.0.1 -U flowsync -d flowsync_system < storage/app/backups/<backup_folder>/system.sql
```

### 7.2 Restoring a Single Tenant Database
```bash
# Drop/recreate tenant database and restore from backup file:
psql -h 127.0.0.1 -U flowsync -d flowsync_tenant_<id> < storage/app/backups/<backup_folder>/tenant_<slug>_<id>.sql
```

### 7.3 Repairing Permissions & Scopes
If new roles or features are added, run the idempotent provisioner and scope backfill:
```bash
# Idempotently repair tenant schema & catalogs
php artisan tenants:provision

# Dry-run scope grant backfill
php artisan tenants:scope-grants

# Apply scope grants
php artisan tenants:scope-grants --force
```
