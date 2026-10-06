# FlowSync — Enterprise Multi-Tenant Administration & HRMS Platform

FlowSync is an enterprise-grade, multi-tenant SaaS application that unifies **Project & Task Management** with a comprehensive **Human Resource Management System (HRMS)**, **Time & Attendance**, **Payroll**, **Performance Reviews**, and **Subscription Billing**.

Built on **Laravel 12** and **React 19**, FlowSync employs a **database-per-tenant physical isolation** architecture backed by PostgreSQL, ensuring uncompromising security, compliance, and multi-tenant performance.

---

## Key Features & Capabilities

### 1. Task & Project Management
- **Workspaces & Projects**: Hierarchical project scoping with customizable keys, statuses, and role-based permissions.
- **Kanban & List Views**: Real-time drag-and-drop task workflow boards powered by `@dnd-kit` and WebSocket events.
- **Task Dependencies**: DAG cycle detection for blocking/dependent tasks with enforcement gates.
- **Collaboration**: Nested comments, mentions, activity streams, and secure signed temporary attachment downloads.

### 2. Complete HRMS Suite
- **Employee Directory**: Profile lifecycle, document records, custom attributes, and interactive organization charts.
- **Time & Attendance**: Shift scheduling, web clock-in/out, geofencing validation, and regularization request flows.
- **Leave Management**: Leave policies, multi-tier accrual balances, and manager approval hierarchies.
- **Payroll & Payslips**: Batch payroll processing, configurable earnings & deductions, and encrypted PDF generation.
- **Performance Reviews**: 360-degree review cycles, goal setting, KPI metrics, and calibration stages.
- **Expenses & Assets**: Employee reimbursement claims and company hardware/equipment lifecycle tracking.

### 3. Multi-Tenancy & Platform Administration
- **Database-Per-Tenant Isolation**: Each tenant has a dedicated PostgreSQL schema/database; central `system` database handles tenant routing, platform RBAC, and subscription billing.
- **Super Admin & Impersonation**: Platform administrators can manage tenants and securely impersonate tenant sessions with full audit logging.
- **Tiered Subscriptions & Limits**: Plan limits enforced across users, storage quotas, workspaces, and projects.
- **Multi-Gateway Billing**: Provider-independent billing supporting **Stripe** and **Razorpay** with webhook verification, idempotency protection, and automated refunds.
- **Full Data Export**: Queued, tenant-isolated JSON/CSV/ZIP data exports with signed time-limited download links.
- **CMS & Website Engine**: Dynamic marketing landing pages and public CMS driven from the central platform.

---

## Technical Stack & Architecture

- **Backend**: Laravel 12 (PHP 8.3+)
- **Frontend**: React 19 SPA, Tailwind CSS v4, Lucide React, Axios
- **Real-Time Communication**: Laravel Reverb + Laravel Echo
- **Databases**:
  - `system`: Central platform database (tenants, subscriptions, payments, audit logs, routing).
  - `tenant_{id}`: Isolated per-tenant databases (workspaces, projects, tasks, HRMS domain records).
- **Queue & Storage**: Redis / Database queue driver, signed temporary file streams on private storage.

---

## Getting Started

### Prerequisites
- PHP 8.3+ with `pdo_pgsql`, `pdo_sqlite`, `zip`, `bcmath`, `gd`, `intl` extensions
- Composer 2+
- Node.js 20+ & npm
- PostgreSQL 16+

### Local Native Installation

1. **Clone the repository and install dependencies**:
   ```bash
   git clone <repository-url> flowsync
   cd flowsync
   composer install
   npm install
   ```

2. **Configure Environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Set up your PostgreSQL database credentials in `.env` for the central system database:
   ```env
   DB_SYSTEM_CONNECTION=pgsql
   DB_SYSTEM_HOST=127.0.0.1
   DB_SYSTEM_PORT=5432
   DB_SYSTEM_DATABASE=flowsync_system
   DB_SYSTEM_USERNAME=flowsync
   DB_SYSTEM_PASSWORD=secret
   ```

3. **Bootstrap the Central System & Provision Tenants**:
   ```bash
   # Run system migrations
   php artisan migrate --database=system --path=database/migrations/system

   # Seed default plans, superadmin, and initial tenants (Acme & Globex)
   php artisan db:seed --database=system --class="Database\Seeders\DatabaseSeeder"

   # Ensure tenant databases are provisioned
   php artisan tenants:provision
   ```

4. **Build Frontend Assets**:
   ```bash
   npm run build
   # Or for development with hot-reload:
   npm run dev
   ```

5. **Run the Application**:
   ```bash
   # Start background queue worker and Reverb websocket server
   php artisan queue:work
   php artisan reverb:start

   # Serve the application
   php artisan serve
   ```

---

## Demo Credentials

All seeded demo accounts use the default password: `password`

| Role | Email | Tenant | Scope |
| :--- | :--- | :--- | :--- |
| **Super Admin** | `superadmin@flowsync.test` | *None* | Central Platform & Tenants |
| **Tenant Admin** | `admin@flowsync.test` | Acme Corp | Acme Tenant Administrator |
| **Tenant Editor** | `editor@flowsync.test` | Acme Corp | Acme Project Member / HR |
| **Tenant Viewer** | `viewer@flowsync.test` | Acme Corp | Acme Read-Only Viewer |
| **Tenant Owner** | `owner@globex.test` | Globex Inc | Globex Tenant Administrator |

---

## Testing & Quality Assurance

FlowSync maintains an extensive automated test suite with rigorous database isolation between tenant tests:

```bash
# Run the complete test suite
php artisan test

# Run focused feature tests
php artisan test tests/Feature/BillingTest.php
php artisan test tests/Feature/TenantExportTest.php
php artisan test tests/Feature/FactoryParityTest.php
php artisan test tests/Feature/BrandSweepTest.php

# Code style linting
./vendor/bin/pint
```

---

## License

FlowSync is proprietary software. All rights reserved.
