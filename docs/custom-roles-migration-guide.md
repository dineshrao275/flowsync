# Custom Roles Scope Migration Guide

This guide describes how the FlowSync own/assigned/all permission model impacts custom tenant roles, how permissions are mapped, and how to safely run the migration tool `php artisan tenants:scope-grants`.

---

## 1. Background

Historically, permissions in FlowSync used unscoped slugs such as `hrms.leave.view` or `tasks.view`.
With the implementation of the Member-Based Access & Scoped Permission Model, domains with a self/team concept now offer three granular variants:

- `*_own`: Grants access strictly to the caller's own records (where the caller is assignee, creator, or subject employee).
- `*_assigned`: Grants access to the caller's own records PLUS direct reports' records (resolved via `employees.manager_id` reporting chain).
- `*_all`: Grants tenant-wide (or project-wide) access to all records in the domain.
- `*.manage`: Administrative management (implies full access and administration).

---

## 2. Legacy Slug Compatibility

During the transition period:
- Legacy unscoped slugs (e.g. `hrms.leave.view`, `tasks.view`) are treated as **aliases for `*_all`** via `PermissionScope::legacyScope()`.
- **Exception**: `hrms.payroll.view` has always meant "view payroll run status + view own payslip". It strictly maps to `hrms.payroll.view_own` and is never widened to read other employees' payslips.
- Code checks using `User::granted()` and `ProjectRole::grants()` understand the scope hierarchy:
  $$\text{own} \subset \text{assigned} \subset \text{all}$$
  Holding `*_all` satisfies checks for `*_assigned` and `*_own`.

---

## 3. Scope Grants Migration (`tenants:scope-grants`)

To make legacy grants explicit in the database and prepare for the eventual deprecation of unsuffixed slugs, run the migration command:

```bash
# Dry-run report (safe, inspects what would be granted without modifying DB)
php artisan tenants:scope-grants

# Dry-run for a specific tenant
php artisan tenants:scope-grants --tenant=1

# Execute migration across all tenant databases
php artisan tenants:scope-grants --force

# Execute migration for a single tenant
php artisan tenants:scope-grants --tenant=1 --force
```

### Migration Rules
1. **Explicit Widening Prevention**: For custom roles holding legacy scoped slugs, the tool grants `X.view_all` + `X.view_own` to preserve identical behavior.
2. **Payroll Safety**: Roles holding `hrms.payroll.view` receive only `hrms.payroll.view_own`. They are NEVER granted `hrms.payroll.view_all`.
3. **Idempotency**: Grants are attached using `syncWithoutDetaching`, meaning existing grants are never removed or damaged.

---

## 4. Configuring New Custom Roles in the UI

In the **Roles** management interface (`/roles`):
- Permissions are grouped logically by domain (e.g., HRMS: Leave, HRMS: Expenses, TMS: Tasks).
- For each domain, select the appropriate scope:
  - **Individual contributors / regular staff**: Grant `*_own`.
  - **Team leads / engineering managers / department managers**: Grant `*_assigned` (plus any needed project-lead role).
  - **Operations / HR Generalists / Auditors**: Grant `*_all`.
  - **Administrators**: Grant `*.manage` or assign the default `admin` role.

---

## 5. Verification

After creating or updating custom roles:
1. Log in with a user assigned to the custom role.
2. Check `/api/auth/me` to verify effective permissions.
3. Access the corresponding list pages to ensure only the intended records are displayed.
