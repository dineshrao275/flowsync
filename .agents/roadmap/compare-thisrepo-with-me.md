# MASTER PROMPT — FLOWSSYNC NEXT-GENERATION HRMS + JIRA-CLASS TMS PRODUCT PLAN

You are acting as a **Principal SaaS Product Architect, Enterprise HRMS Architect, JIRA/TMS Product Architect, Multi-Tenant SaaS Architect, Security Architect, and Technical Program Manager**.

I am building **FlowSync**, a multi-tenant SaaS platform that combines:

1. **Enterprise HRMS** comparable in functional breadth to KEKA
2. **Project & Task Management / TMS** comparable in functional breadth to JIRA
3. A unified identity, role/permission, workflow, approval, notification, reporting and audit layer
4. Subscription-based SaaS plans where HRMS and TMS capabilities are exposed as configurable modules/features
5. Deep synchronization between HRMS and TMS

I have attached the current FlowSync development documentation.

## ATTACHED SOURCE DOCUMENTS

Treat the attached files as the **primary source of truth for the current implementation**:

- `hrms-implementation-plan.md`
- `multi-tenancy-architecture.md`
- `custom-roles-migration-guide.md`
- `member-access-plan.md`
- `phase-plan.md`
- `final-report.md`
- `AGENTS.md`

You MUST inspect and understand these documents before producing the plan.

Do NOT assume that a feature is missing merely because it is not obvious from one document.

Cross-reference all documents and classify every capability as:

- ✅ IMPLEMENTED / VERIFIED
- 🟡 PARTIALLY IMPLEMENTED
- 🟠 IMPLEMENTED BUT NEEDS HARDENING
- 🔵 RESERVED / STUB
- 🔴 MISSING
- ⏸️ DEFERRED BY DESIGN
- ❓ REQUIRES CODEBASE VERIFICATION

Do not silently change this classification based on assumptions.

---

# 1. PRIMARY OBJECTIVE

Create the **complete product architecture and next-phase development master plan** for FlowSync.

The objective is NOT merely to create another implementation checklist.

I need a plan that answers:

> “If we want FlowSync to become a serious enterprise SaaS product combining a KEKA-class HRMS and a JIRA-class Project/Task Management platform, what exactly should exist, how should everything work together, what do we already have, what is missing, what should change, and in what order should we build it?”

The final plan must cover the entire product from:

**Super Admin → SaaS Platform → Subscription → Tenant → Organization → Users → Employees → Roles → Permissions → HRMS → Projects → Tasks → Workflows → Approvals → Billing → Reporting → Audit → Integrations**

Do not leave major functional areas unexplored.

---

# 2. FIRST: AUDIT OUR CURRENT DEVELOPMENT

Before proposing anything new, perform a rigorous audit of the attached documentation.

Create a:

## CURRENT STATE ASSESSMENT

For every existing module/functionality identify:

| Domain | Feature | Current Status | Evidence | Existing Implementation | Problems | Recommended Action |
|---|---|---|---|---|---|---|

For each feature determine:

- Is it actually implemented?
- Is it only documented?
- Is it tested?
- Is the UI implemented?
- Is the API implemented?
- Is authorization implemented?
- Is tenant isolation enforced?
- Is subscription/module entitlement enforced?
- Are workflows implemented?
- Are audit logs implemented?
- Are notifications implemented?
- Are edge cases handled?
- Is it production-ready?
- Does it need redesign?

IMPORTANT:

Do not recreate already-shipped work.

Instead, identify whether the current architecture is strong enough to support the next stage.

---

# 3. DETERMINE WHETHER THE CURRENT ARCHITECTURE IS ON THE RIGHT PATH

Give a direct architectural verdict:

## “ARE WE ON THE RIGHT PATH?”

Evaluate:

### Backend
- Laravel architecture
- Domain separation
- Services
- Policies
- Form Requests
- Presenters
- Enums
- Jobs
- Events
- Notifications
- Approval engine
- Audit logging
- Reporting
- Search
- API architecture

### Frontend
- React architecture
- Routing
- Layouts
- reusable components
- state management
- permissions
- module gates
- responsive UX
- dashboard architecture
- forms
- tables
- filters
- pagination
- realtime UI

### Database
- System DB
- Tenant DB
- database-per-tenant model
- relationships
- indexes
- audit tables
- workflow tables
- permissions
- subscription tables
- reporting tables
- scalability

### SaaS
- tenant provisioning
- onboarding
- subscription lifecycle
- plan entitlements
- feature flags
- quotas
- billing
- payment gateways
- cancellation
- renewals
- upgrades/downgrades
- trial
- suspension
- tenant deletion
- tenant export
- tenant backup/restore

### Security
- tenant isolation
- RBAC
- scoped permissions
- own/assigned/all
- impersonation
- audit logs
- sensitive data
- signed downloads
- API security
- session security
- rate limiting
- file security
- authorization consistency

Give each area a rating:

**Excellent / Good / Needs Improvement / Architectural Risk / Redesign Required**

Explain why.

---

# 4. BUILD A COMPLETE HRMS FEATURE CATALOG

Now design the complete HRMS capability matrix.

Use KEKA-like enterprise HRMS breadth as a functional reference, but DO NOT copy proprietary implementation, UI, wording, or intellectual property.

Cover at minimum:

## HR CORE

- Employee directory
- Employee profile
- Personal information
- Employment information
- Job information
- Departments
- Designations
- Locations
- Reporting managers
- Organization hierarchy
- Employee status
- Employment types
- Joining/exit dates
- Employee lifecycle
- Employee history
- Custom employee fields
- Employee tags
- Employee search
- Bulk employee operations

## EMPLOYEE SELF SERVICE

- My profile
- My documents
- My attendance
- My leave
- My expenses
- My payslips
- My assets
- My goals
- My performance
- My feedback
- My surveys
- My approvals
- My tasks
- My work logs
- Notifications
- Preferences

## ORGANIZATION MANAGEMENT

- Departments
- Designations
- Locations
- Reporting hierarchy
- Matrix reporting
- Business units
- Legal entities
- Cost centers
- Teams
- Organization chart
- Manager hierarchy
- Employee transfers
- Promotions
- Role changes
- Confirmation
- Probation

## ONBOARDING

- Offer-to-joining workflow
- New employee creation
- Onboarding templates
- Tasks
- Document collection
- Verification
- E-signature
- IT provisioning
- Asset allocation
- Manager assignment
- Buddy assignment
- Orientation
- Checklist
- Automated reminders
- Onboarding completion

## OFFBOARDING

- Resignation
- Notice period
- Exit workflow
- Manager approval
- HR approval
- Exit interview
- Asset recovery
- Document clearance
- Payroll settlement
- Leave settlement
- Full & final settlement
- Access revocation
- Account deactivation
- Experience/relieving documents
- Exit analytics

## ATTENDANCE

- Clock in/out
- Web attendance
- Mobile attendance
- Geo-fencing
- IP restrictions
- Remote attendance
- Shift attendance
- Attendance policies
- Late arrival
- Early leaving
- Missing punch
- Regularization
- Overtime
- Breaks
- Attendance correction
- Attendance calendar
- Attendance reports
- Attendance exports
- Attendance approval workflow

## SHIFTS

- Shift patterns
- Fixed shifts
- Rotational shifts
- Flexible shifts
- Shift assignments
- Shift rosters
- Weekly offs
- Split shifts
- Night shifts
- Shift swaps
- Shift approvals
- Shift exceptions

## LEAVE MANAGEMENT

- Leave types
- Leave policies
- Leave balances
- Accruals
- Carry forward
- Encashment
- Half day
- Hourly leave
- Optional holidays
- Leave blackout periods
- Leave restrictions
- Leave approval chains
- Manager approval
- HR approval
- Delegation
- Leave calendar
- Team leave visibility
- Leave cancellation
- Leave withdrawal
- Leave adjustment
- Leave exemption
- Statutory leave rules

## COMP-OFF

- Eligibility
- Accrual
- Approval
- Credit
- Expiry
- Redemption
- Balance
- Adjustment
- Reports

## HOLIDAYS

- Holiday calendars
- Country calendars
- State/region calendars
- Optional holidays
- Tenant holidays
- Location-specific holidays
- Employee-specific holidays
- Holiday import
- Holiday approval

## EXPENSES

- Expense categories
- Expense policies
- Expense limits
- Mileage
- Travel
- Meals
- Receipts
- OCR-ready architecture
- Multi-currency
- Tax
- Reimbursement
- Approval chains
- Finance approval
- Payroll integration
- Expense reports
- Corporate expenses
- Advances
- Settlement

## COMPENSATION

- Salary structures
- Salary components
- Earnings
- Deductions
- CTC
- Salary revisions
- Promotions
- Increment cycles
- Effective dates
- Compensation letters
- Compensation history
- Approval workflow

## PAYROLL

- Payroll periods
- Payroll runs
- Salary calculation
- Attendance integration
- Leave integration
- Overtime
- Bonuses
- Incentives
- Deductions
- Loans/advances
- Reimbursements
- Tax
- Payslips
- Payroll approval
- Payroll locking
- Payroll reopening
- Payroll audit
- Payroll reports
- Payroll export
- Bank file generation architecture
- Payroll integrations

## STATUTORY / COMPLIANCE

Design an extensible jurisdiction engine.

Include:

- PF
- ESI
- PT
- TDS
- LWF
- Tax declarations
- Exemptions
- Investment declarations
- Statutory calculations
- Compliance reports
- Challan data
- Return preparation
- Filing integration architecture
- Compliance audit
- Effective-date rules

Clearly separate:

**calculation → preparation → filing → external integration**

because these have different risk levels.

## DOCUMENT MANAGEMENT

- Employee documents
- Document types
- Expiry
- Verification
- Confidential documents
- Sensitive documents
- Document versioning
- Access control
- Download audit
- Upload
- Replacement
- Document requests
- Employee document submission
- HR verification

## ASSETS

- Asset categories
- Asset inventory
- Assignment
- Return
- Maintenance
- Damage
- Replacement
- Asset history
- Employee assets
- IT assets
- Asset lifecycle

## PERFORMANCE

- Performance cycles
- Goals
- KPIs
- OKRs
- Goal alignment
- Goal → project → task linking
- Check-ins
- One-on-ones
- Feedback
- Reviews
- Ratings
- Competencies
- Self review
- Manager review
- Peer review
- 360 feedback
- Calibration
- Promotion recommendation
- Performance improvement plans
- Performance history

## TALENT

Do not treat performance and talent as identical.

Design:

- Competency framework
- Skills
- Skill matrix
- Career paths
- Succession planning
- Talent pools
- High-potential employees
- Development plans
- Internal mobility

## ENGAGEMENT

- Surveys
- Pulse surveys
- Anonymous surveys
- Employee engagement
- eNPS
- Feedback
- Campaigns
- Results
- Segmentation
- Privacy controls

## HR ANALYTICS

- Headcount
- Attrition
- Attendance
- Leave
- Payroll
- Compensation
- Hiring
- Performance
- Engagement
- Diversity-ready architecture
- Department analytics
- Manager analytics
- Cost analytics
- Employee lifecycle analytics
- Custom reports
- Scheduled reports
- Exports

---

# 5. HRMS FEATURES CURRENTLY DEFERRED

Explicitly inspect the existing documentation for deferred functionality.

Do not automatically assume deferred means “must build immediately”.

Create:

| Deferred Feature | Why Deferred | Should Build? | Priority | Dependencies |
|---|---|---|---|---|

Pay special attention to:

- ATS
- Recruitment
- Job postings
- Candidates
- Interviews
- Applicant portal
- Training
- LMS
- Certifications
- Transfers
- Promotions
- Confirmation
- Statutory filing
- Payroll vendor integrations
- Bank integrations
- Advanced organization chart

---

# 6. BUILD A COMPLETE JIRA-CLASS TMS FEATURE CATALOG

Do NOT limit TMS to basic tasks.

Design the platform to support multiple methodologies:

- Kanban
- Scrum
- Agile
- Waterfall
- Hybrid

Cover:

## WORKSPACE

- Workspaces
- Workspace members
- Workspace roles
- Workspace settings
- Workspace templates
- Workspace-level permissions

## PROJECTS

- Projects
- Project templates
- Project members
- Project roles
- Project settings
- Project permissions
- Project archive
- Project clone
- Project health

## ISSUE / TASK TYPES

Support configurable issue types:

- Task
- Bug
- Story
- Epic
- Sub-task
- Initiative
- Milestone
- Improvement
- Request
- Risk
- Change
- Custom issue types

## ISSUE HIERARCHY

Design:

Initiative
→ Epic
→ Story
→ Task
→ Sub-task

and explain how this interacts with:

- projects
- sprints
- roadmaps
- reporting
- permissions

## TASK MANAGEMENT

- Create
- Edit
- Delete
- Assign
- Watch
- Follow
- Subscribe
- Priorities
- Labels
- Components
- Due dates
- Start dates
- Estimates
- Story points
- Time tracking
- Checklists
- Attachments
- Comments
- Mentions
- Activity history
- Dependencies
- Blocking
- Related issues
- Parent/child
- Clone
- Move
- Bulk edit
- Bulk assign
- Bulk transition

## VIEWS

- List
- Board
- Kanban
- Scrum board
- Calendar
- Timeline
- Gantt
- Roadmap
- Workload
- My work
- Team work
- Backlog

## WORKFLOW ENGINE

Design a generic workflow engine supporting:

- statuses
- transitions
- transition permissions
- validators
- conditions
- post-actions
- approvals
- automation
- required fields
- notifications
- SLAs
- workflow schemes

Example:

Open
→ Selected
→ In Progress
→ Code Review
→ QA
→ UAT
→ Done

But make this configurable per project/workspace.

## SPRINTS

- Sprint creation
- Sprint planning
- Backlog
- Sprint start
- Sprint close
- Carry-over
- Velocity
- Burndown
- Burnup
- Capacity
- Sprint reports

## EPICS / ROADMAPS

- Epics
- Releases
- Versions
- Milestones
- Roadmaps
- Dependencies
- Cross-project planning
- Portfolio view

## JQL-LIKE SEARCH

Design a powerful query/search system.

Example:

`project = APP AND status = "In Progress" AND assignee = currentUser()`

Cover:

- filtering
- saved filters
- sharing
- permissions
- query parser
- indexed search
- advanced filters

## AUTOMATION

Design:

WHEN
→ CONDITION
→ ACTION

Examples:

- When task moves to Done → notify reporter
- When due date is near → notify assignee
- When priority becomes Critical → notify project lead
- When task is blocked → notify dependency owner
- When employee joins → create onboarding tasks
- When leave is approved → create/adjust related task
- When payroll closes → lock related HR workflows

## NOTIFICATIONS

- In-app
- Email
- Push-ready architecture
- Mentions
- Assignment
- Status changes
- Comments
- Due dates
- Approvals
- Automation notifications
- Notification preferences
- Digest notifications

## TIME TRACKING

- Work logs
- Timers
- Timesheets
- Billable/non-billable
- Project time
- Task time
- Approval
- Reports
- Payroll linkage

## REPORTS

- Project health
- Task distribution
- Velocity
- Burndown
- Burnup
- Cycle time
- Lead time
- Throughput
- Workload
- Team performance
- Time tracking
- SLA
- Custom reports

## DASHBOARDS

- Personal
- Team
- Project
- Workspace
- Executive
- Custom widgets
- Filters
- Saved dashboards
- Permission-aware metrics

## SERVICE MANAGEMENT / REQUESTS

Evaluate whether FlowSync should support:

- service requests
- forms
- request types
- queues
- SLAs
- priorities
- escalation
- assignment rules
- approvals

If this belongs in a separate module, explicitly say so.

---

# 7. HRMS + TMS MUST BE DEEPLY INTEGRATED

This is one of the most important parts of the product.

Do NOT design HRMS and TMS as two unrelated applications.

Create a complete:

# HRMS ↔ TMS INTEGRATION ARCHITECTURE

Map relationships such as:

Employee ↔ User

Employee ↔ Workspace Member

Employee ↔ Project Member

Employee ↔ Project Role

Employee ↔ Task Assignee

Employee ↔ Task Reporter

Employee ↔ Manager

Employee ↔ Work Log

Employee ↔ Attendance

Employee ↔ Leave

Employee ↔ Performance Goal

Performance Goal ↔ Project

Performance Goal ↔ Epic

Performance Goal ↔ Task

Expense ↔ Project

Expense ↔ Task

Attendance ↔ Work Logs

Leave ↔ Task availability

Employee onboarding ↔ automated tasks

Employee offboarding ↔ automated tasks

Performance ↔ completed tasks

Project workload ↔ employee workload

Payroll ↔ approved work/expense data where appropriate

Asset assignment ↔ employee

Notifications ↔ HRMS + TMS

Approvals ↔ HRMS + TMS

Audit ↔ HRMS + TMS

Explain which integrations are:

- synchronous
- asynchronous
- event-driven
- read-only
- bidirectional

---

# 8. DESIGN A SHARED WORKFLOW / APPROVAL ENGINE

Do not create independent approval systems for every module.

Design a reusable workflow/approval engine.

It must support:

- multi-step approvals
- sequential approval
- parallel approval
- any-one approval
- all approval
- conditional approval
- manager approval
- role-based approval
- department-based approval
- dynamic approvers
- escalation
- delegation
- rejection
- cancellation
- resubmission
- SLA
- reminders
- audit trail

Show how the same engine works for:

- Leave
- Expense
- Attendance regularization
- Comp-off
- Payroll
- Salary revision
- Offboarding
- Documents
- TMS task approvals
- Project change requests
- Purchase/request workflows

---

# 9. DESIGN THE RBAC + ABAC + SCOPED PERMISSION MODEL

Current FlowSync already uses:

`own → assigned → all`

and `manage`.

Do not throw this away.

Evaluate whether it should evolve into a more comprehensive model.

Design:

### PLATFORM LEVEL

- Super Admin
- Billing Admin
- Support Admin
- Platform Auditor
- Platform Operator

### TENANT LEVEL

- Tenant Owner
- Tenant Admin
- HR Admin
- HR Manager
- HR Executive
- Finance Admin
- Payroll Admin
- Manager
- Team Lead
- Employee
- Auditor
- Project Admin
- Project Manager
- Developer
- Contributor
- Viewer
- Custom Roles

### SCOPES

- own
- assigned
- team
- department
- location
- project
- workspace
- tenant/all

Evaluate whether additional scopes are required.

For every permission design:

`resource + action + scope + context`

Example:

`hrms.leave.view_own`

`hrms.leave.view_assigned`

`hrms.leave.view_department`

`hrms.leave.view_all`

`tasks.edit_own`

`tasks.edit_assigned`

`tasks.edit_project`

`tasks.manage`

Also explain:

- role inheritance
- permission precedence
- deny rules
- custom roles
- project-level permissions
- tenant-level permissions
- module-level permissions
- subscription entitlements
- UI capability checks
- API authorization
- record-level policy authorization

Never rely only on frontend hiding.

---

# 10. TENANT ARCHITECTURE

Current architecture uses:

**Central System DB + one PostgreSQL database per tenant.**

Do NOT casually replace this.

Evaluate it for:

- 10 tenants
- 100 tenants
- 1,000 tenants
- 10,000 tenants
- 100,000 tenants

Discuss:

- provisioning
- migrations
- connection pooling
- backups
- restore
- tenant deletion
- tenant suspension
- tenant cloning
- tenant export
- disaster recovery
- database version upgrades
- encryption
- tenant routing
- cross-tenant analytics
- global search
- central reporting
- background jobs
- queues
- realtime
- scheduled jobs

Recommend an architecture that preserves tenant isolation while remaining operationally scalable.

---

# 11. SUBSCRIPTION / SaaS MODEL

This is a core product capability.

Design the complete:

# PLAN → ENTITLEMENT → USAGE → BILLING → LIMIT → ENFORCEMENT SYSTEM

Plans should be configurable.

Example:

### Starter
Basic TMS

### Professional
TMS + core HRMS

### Business
Advanced HRMS + TMS

### Enterprise
Everything + advanced security/integrations/compliance

But DO NOT blindly use these exact plans.

Determine the best commercial packaging.

Each plan must define:

- modules
- features
- limits
- users/seats
- storage
- projects
- workspaces
- tasks
- API calls
- automation executions
- workflow executions
- reports
- integrations
- audit retention
- backup retention
- support level
- SSO
- API access
- webhooks
- custom fields
- advanced analytics
- custom branding

Design:

- trial
- upgrade
- downgrade
- renewal
- failed payment
- grace period
- suspension
- cancellation
- reactivation
- refunds
- prorating
- seat changes
- overage
- quotas
- feature overrides
- grandfathered plans

---

# 12. FEATURE ENTITLEMENT ENGINE

Design a clean hierarchy:

Subscription
→ Plan
→ Module
→ Feature
→ Capability
→ Permission
→ Record Scope

Example:

Plan contains:

`hrms.attendance`

Feature contains:

`attendance.remote_punch`

Capability:

`attendance.punch.create`

Permission:

`hrms.attendance.punch_own`

Scope:

`own`

Explain exactly where each decision is evaluated.

Avoid duplicated entitlement logic across frontend/backend.

---

# 13. TENANT WORKFLOW CUSTOMIZATION

Every tenant should be able to customize where appropriate.

Design tenant-configurable:

- workflows
- approval chains
- leave policies
- attendance policies
- shifts
- holidays
- expense policies
- payroll settings
- performance cycles
- task workflows
- issue types
- priorities
- statuses
- project templates
- notification rules
- automation rules
- roles
- permissions
- custom fields
- forms

Distinguish:

### Platform defaults
### Tenant configuration
### Tenant overrides
### User-specific settings

---

# 14. CUSTOM FIELD ENGINE

Evaluate whether FlowSync needs a generic custom field engine.

It should potentially support:

- text
- number
- currency
- date
- datetime
- boolean
- select
- multi-select
- user
- employee
- project
- task
- department
- attachment
- formula

It must work across:

- Employees
- Projects
- Tasks
- Expenses
- Assets
- Documents
- Requests

Explain storage strategy and querying strategy.

---

# 15. AUTOMATION ENGINE

Design a reusable automation engine shared by HRMS + TMS.

Architecture:

EVENT
→ CONDITIONS
→ ACTIONS

Events:

- employee.created
- employee.joined
- employee.updated
- employee.offboarded
- leave.submitted
- leave.approved
- attendance.regularization_requested
- expense.submitted
- expense.approved
- task.created
- task.assigned
- task.status_changed
- task.overdue
- sprint.started
- sprint.completed
- project.created
- payroll.completed

Actions:

- create task
- assign task
- change status
- send notification
- send email
- create approval
- update employee
- update field
- create project
- create checklist
- webhook
- API call

---

# 16. REPORTING / ANALYTICS ARCHITECTURE

Design reporting so that HRMS and TMS can eventually provide cross-domain insights.

Examples:

- Employee workload
- Project workload
- Attendance vs productivity
- Leave vs delivery
- Work logs vs payroll
- Employee performance vs task completion
- Project cost vs employee cost
- Team utilization
- Capacity planning
- Attrition vs workload
- Manager effectiveness
- Project staffing

Clearly distinguish:

Operational queries
→ Reporting queries
→ Analytics
→ Aggregation
→ Data warehouse / OLAP

Do not create expensive cross-tenant joins inside transactional tenant databases.

---

# 17. AUDIT / COMPLIANCE

Create one unified audit strategy.

Track:

- login
- logout
- role changes
- permission changes
- employee changes
- salary changes
- payroll changes
- leave changes
- attendance changes
- task changes
- workflow changes
- subscription changes
- billing
- exports
- downloads
- impersonation
- API access
- administrative actions

Define:

- actor
- tenant
- resource
- action
- timestamp
- IP
- user agent
- before
- after
- correlation ID

Sensitive data must never be exposed in logs.

---

# 18. NOTIFICATIONS

Create a unified notification architecture for HRMS + TMS.

Channels:

- In-app
- Email
- Push-ready
- Webhook

Notification events should support:

- user preference
- tenant policy
- plan entitlement
- throttling
- batching
- digest
- localization
- templates
- deep links

---

# 19. SEARCH

Design a unified search architecture.

Search:

- Employees
- Projects
- Tasks
- Documents
- Comments
- Workspaces
- Assets
- Leave
- Expenses
- Reports

Include:

- permission-aware search
- tenant-aware search
- fuzzy search
- filters
- saved searches
- indexing strategy
- PostgreSQL search
- future Elasticsearch/OpenSearch migration path

---

# 20. API / INTEGRATION PLATFORM

Design:

- REST API
- API tokens
- OAuth2/OIDC if appropriate
- webhooks
- API rate limits
- API permissions
- API scopes
- integration logs
- retries
- idempotency
- versioning

Potential integrations:

- Google Workspace
- Microsoft 365
- Slack
- Teams
- GitHub
- GitLab
- Jira
- Zoom
- payroll providers
- accounting software
- identity providers
- SSO
- biometric attendance devices

Classify each integration as:

MVP / Phase 2 / Enterprise / Future.

---

# 21. ADMIN / SUPER ADMIN PLATFORM

Design the complete central admin platform.

Super Admin should manage:

- tenants
- subscriptions
- plans
- features
- modules
- usage
- billing
- payments
- invoices
- failed payments
- tenant provisioning
- tenant suspension
- tenant restoration
- impersonation
- support access
- audit logs
- platform settings
- feature flags
- system health
- jobs
- queues
- backups
- exports
- migrations
- tenant database status
- tenant activity
- platform analytics

---

# 22. TENANT ADMIN

Tenant admins should manage:

- company
- employees
- teams
- departments
- roles
- permissions
- workflows
- approval chains
- subscription
- billing
- modules
- policies
- settings
- integrations
- notifications
- branding
- security
- audit

---

# 23. UX / INFORMATION ARCHITECTURE

Design a modern SaaS navigation architecture.

Avoid the current problem of having dozens of top-level navigation items.

Design:

- Dashboard
- Work
- HR
- Projects
- Tasks
- Reports
- Analytics
- Admin
- Settings

Then design contextual sub-navigation.

Explain navigation based on:

- role
- permission
- subscription
- module
- feature
- tenant configuration

---

# 24. MOBILE / RESPONSIVE STRATEGY

Determine which functionality should be mobile-first.

HR:

- punch in/out
- attendance
- leave
- approvals
- expenses
- notifications
- payslips
- employee directory

TMS:

- my tasks
- task updates
- comments
- approvals
- notifications
- work logs

Design mobile capability boundaries.

---

# 25. SECURITY THREAT MODEL

Perform a threat model covering:

- cross-tenant access
- IDOR
- privilege escalation
- role manipulation
- permission bypass
- subscription bypass
- signed URL abuse
- file upload attacks
- XSS
- CSRF
- SQL injection
- SSRF
- webhook attacks
- replay attacks
- session hijacking
- impersonation abuse
- API token theft
- sensitive-data leakage
- audit manipulation

Give mitigations and tests.

---

# 26. DATA LIFECYCLE

Define lifecycle for:

- employee
- user
- task
- project
- workspace
- document
- asset
- leave
- expense
- payroll
- subscription
- tenant

Include:

create
→ active
→ archived
→ suspended
→ deleted
→ retention
→ purge

Be explicit about legal/compliance retention requirements.

---

# 27. PERFORMANCE / SCALE

Plan for:

- 10k tenants
- 100k employees
- millions of tasks
- millions of work logs
- millions of attendance records
- large payroll datasets

Identify:

- indexes
- caching
- queues
- async jobs
- database partitioning
- read replicas
- connection pooling
- Redis
- object storage
- search indexes
- reporting database
- data warehouse

---

# 28. OBSERVABILITY

Design:

- structured logs
- correlation IDs
- metrics
- traces
- queue monitoring
- failed jobs
- tenant health
- database health
- API latency
- error rate
- billing failures
- provisioning failures

Define production alerts.

---

# 29. TESTING STRATEGY

Create a complete testing matrix.

### Unit
### Feature
### Integration
### Authorization
### Tenant isolation
### Subscription entitlement
### Workflow
### Billing
### Webhooks
### Security
### Performance
### Browser/E2E
### Mobile
### Migration
### Backup/restore

Especially require matrix testing:

Tenant
× Plan
× Role
× Permission
× Scope
× Module
× Resource

---

# 30. IDENTIFY THE BIGGEST CURRENT GAPS

After auditing the current documentation, create:

# GAP REGISTER

| Priority | Domain | Missing/Weak Feature | Current State | Business Impact | Technical Impact | Dependencies | Recommendation |
|---|---|---|---|---|---|---|---|

Use:

P0 = security/data-loss/blocking architecture

P1 = required for enterprise product

P2 = important product capability

P3 = enhancement

P4 = future/optional

---

# 31. DO NOT REBUILD WHAT ALREADY EXISTS

This is extremely important.

If something already exists:

DO NOT create another implementation phase for it.

Instead classify:

- Keep
- Refactor
- Extend
- Harden
- Optimize
- Replace
- Verify

For example, if scoped permissions already exist, do not design a completely new RBAC system unless there is a proven architectural limitation.

---

# 32. FIND CROSS-MODULE DUPLICATION

Identify duplicate systems that should become shared infrastructure.

Examples:

- approval systems
- notifications
- audit
- attachments
- comments
- users
- employee identity
- workflows
- custom fields
- search
- reporting
- automation
- activity history
- permissions

Recommend shared platform primitives.

---

# 33. DESIGN THE TARGET DOMAIN ARCHITECTURE

Produce a target bounded-context architecture.

Example:

Platform
├── Identity
├── Tenancy
├── Billing
├── Entitlements
├── Audit
├── Notifications
├── Automation
├── Workflow
├── Files
├── Search
├── Reporting
│
├── HRMS
│   ├── People
│   ├── Organization
│   ├── Attendance
│   ├── Leave
│   ├── Expenses
│   ├── Compensation
│   ├── Payroll
│   ├── Performance
│   ├── Talent
│   ├── Engagement
│   ├── Documents
│   └── Assets
│
└── TMS
    ├── Workspaces
    ├── Projects
    ├── Issues
    ├── Backlog
    ├── Sprints
    ├── Boards
    ├── Roadmaps
    ├── Reports
    └── Time Tracking

Improve this architecture if required.

---

# 34. CREATE THE TARGET DATABASE MODEL

Provide a conceptual ERD covering:

### System DB

- tenants
- subscription_plans
- subscriptions
- payments
- payment_events
- tenant routing
- platform users
- platform audit
- usage
- provisioning
- feature flags

### Tenant DB

- users
- roles
- permissions
- employees
- organization
- HRMS
- projects
- tasks
- workflows
- approvals
- notifications
- audit
- files
- reports
- automation

Identify missing entities and relationships.

---

# 35. DEFINE THE EVENT ARCHITECTURE

Design domain events such as:

`EmployeeCreated`

`EmployeeJoined`

`EmployeeOffboarded`

`LeaveSubmitted`

`LeaveApproved`

`AttendanceRegularized`

`ExpenseApproved`

`PayrollCompleted`

`TaskCreated`

`TaskAssigned`

`TaskStatusChanged`

`TaskCompleted`

`SprintCompleted`

`ProjectCreated`

`SubscriptionChanged`

Then show consumers:

Event
→ notification
→ audit
→ automation
→ analytics
→ integration

---

# 36. CREATE THE COMPLETE IMPLEMENTATION ROADMAP

After the audit, create the roadmap.

Do NOT organize it simply as “HRMS phase” and “TMS phase”.

Instead organize by architectural dependency.

Example:

### Phase 0 — Baseline & Architecture Verification

### Phase 1 — Shared Platform Primitives

### Phase 2 — Identity / RBAC / ABAC

### Phase 3 — Tenant / Subscription / Entitlement

### Phase 4 — Workflow / Approval / Automation

### Phase 5 — HRMS Completion

### Phase 6 — TMS/JIRA Completion

### Phase 7 — HRMS ↔ TMS Deep Integration

### Phase 8 — Reporting / Analytics

### Phase 9 — Integrations / API Platform

### Phase 10 — Enterprise Security

### Phase 11 — Scale / Performance

### Phase 12 — Mobile

### Phase 13 — Enterprise / Compliance

Modify this ordering if your audit indicates a better dependency chain.

---

# 37. EVERY PHASE MUST CONTAIN

For every phase provide:

## Objective

## Why now?

## Dependencies

## Features

## Backend work

## Database changes

## API changes

## Frontend changes

## Permissions

## Subscription entitlements

## Workflow impact

## HRMS/TMS integration impact

## Events

## Notifications

## Audit requirements

## Security requirements

## Tests

## Migration strategy

## Backward compatibility

## Performance concerns

## Deployment strategy

## Rollback strategy

## Acceptance criteria

## Definition of Done

---

# 38. BREAK PHASES INTO SMALL IMPLEMENTABLE TASKS

Each task must be independently actionable.

Format:

`P1.1`

`P1.2`

`P1.3`

etc.

Each task should include:

- objective
- files/components likely affected
- DB migration
- API
- UI
- permission
- tests
- dependencies
- acceptance criteria
- risk
- estimated complexity

Avoid giant tasks like:

> “Implement complete payroll.”

Instead split it into logical vertical slices.

---

# 39. MIGRATION SAFETY

The current product is already developed.

Therefore every new phase must explain:

- migration impact
- existing tenant impact
- existing role impact
- existing permission impact
- existing subscription impact
- existing data impact
- backward compatibility
- rollout strategy
- rollback strategy

Never recommend destructive migrations without a migration path.

---

# 40. EXISTING PERMISSION MIGRATION

Respect the existing own/assigned/all model.

Current semantic hierarchy:

`own ⊂ assigned ⊂ all`

and `manage` represents administrative access.

Evaluate where additional scopes such as:

- team
- department
- location
- project
- workspace

are necessary.

Do not silently widen custom roles.

Existing legacy permission migration behavior must remain safe and idempotent.

---

# 41. PRODUCT PACKAGING

Design the actual commercial SaaS packaging.

Provide at least 3 possible packaging strategies:

### Option A — Unified plans

### Option B — Base TMS + HRMS add-on

### Option C — Modular consumption

Then recommend one.

Explain:

- pricing psychology
- seat pricing
- employee pricing
- admin pricing
- module pricing
- enterprise pricing
- usage-based pricing
- storage
- automation
- API
- integrations

---

# 42. COMPETITIVE PRODUCT GAP

Without copying competitors, compare the intended capability breadth conceptually against:

- KEKA-like HRMS
- JIRA-like TMS
- Monday-like work management
- ClickUp-like work management
- Asana-like project management
- Zoho People-like HRMS
- BambooHR-like HRMS
- Rippling-like employee platform

Identify:

- table-stakes features
- differentiators
- unnecessary complexity
- opportunities unique to FlowSync

---

# 43. FINAL PRODUCT VISION

Define what FlowSync should become in one sentence.

Then define:

### Core value proposition

### Target customers

### Ideal customer profile

### Primary personas

### Core workflows

### Differentiators

### Modules

### Commercial model

### Enterprise readiness

---

# 44. FINAL OUTPUT STRUCTURE

The final document MUST be structured exactly as:

# FLOWSSYNC — MASTER PRODUCT & DEVELOPMENT ROADMAP

## 1. Executive Summary

## 2. Current Product State

## 3. Architecture Verdict — Are We on the Right Path?

## 4. Existing Feature Inventory

## 5. HRMS Complete Capability Matrix

## 6. TMS/JIRA Complete Capability Matrix

## 7. HRMS ↔ TMS Integration Matrix

## 8. Roles & Permissions Architecture

## 9. Tenant Architecture

## 10. Subscription & Entitlement Architecture

## 11. Workflow & Approval Architecture

## 12. Automation Architecture

## 13. Notification Architecture

## 14. Search Architecture

## 15. Reporting & Analytics Architecture

## 16. API & Integration Architecture

## 17. Security Architecture

## 18. Data Lifecycle

## 19. Scalability Architecture

## 20. Testing Strategy

## 21. Gap Register

## 22. Deferred Features

## 23. Technical Debt Register

## 24. Recommended Target Architecture

## 25. Database Architecture

## 26. Event Architecture

## 27. Subscription Packaging

## 28. Product Differentiation

## 29. Master Development Roadmap

## 30. Phase-by-Phase Implementation Plan

## 31. Task-by-Task Development Plan

## 32. Migration & Rollout Strategy

## 33. Risk Register

## 34. Definition of Done

## 35. Recommended Immediate Next Phase

---

# 45. CRITICAL RULES

Follow these rules throughout the analysis:

1. **Do not assume documentation equals implementation.**
2. **Do not assume implementation equals production readiness.**
3. **Do not rebuild shipped functionality.**
4. **Do not remove the current tenant isolation architecture without strong evidence.**
5. **Do not weaken tenant isolation for convenience.**
6. **Do not rely on frontend permissions.**
7. **Every API authorization decision must be server-side.**
8. **Subscription entitlement and RBAC are different concerns.**
9. **Module availability and record access are different concerns.**
10. **Keep HRMS and TMS synchronized through shared domain primitives/events rather than duplicated logic.**
11. **Prefer reusable engines over module-specific implementations.**
12. **Do not silently widen custom roles.**
13. **Do not introduce breaking database migrations without migration strategy.**
14. **Every major feature must have tests.**
15. **Every sensitive operation must be auditable.**
16. **Every tenant operation must respect tenant isolation.**
17. **Every new feature must consider subscription entitlement.**
18. **Every workflow must define authorization and audit behavior.**
19. **Every asynchronous job must preserve tenant context.**
20. **Do not create unnecessary microservices prematurely.**
21. **Prefer modular monolith architecture unless there is a proven reason to split services.**
22. **Do not optimize prematurely; identify measurable scale thresholds.**
23. **Do not treat reserved/stub modules as implemented.**
24. **Clearly distinguish facts from recommendations.**
25. **If the attached documents conflict, identify the conflict instead of silently choosing one.**
26. **If something cannot be verified from the attached material, explicitly mark it `REQUIRES CODEBASE VERIFICATION`.**
27. **Do not invent implementation details that are not supported by the source documents.**

---

# 46. MOST IMPORTANT FINAL QUESTION

At the end, answer this explicitly:

> **“If I continue development from the current codebase today, what should I build NEXT, in exactly what order, and why?”**

Give me:

### Top 10 immediate development priorities

For each:

- feature
- reason
- dependency
- business value
- technical value
- risk if postponed
- estimated complexity
- recommended phase
- acceptance criteria

Then provide the **single recommended next phase** that should be given to an AI coding agent such as Claude Code/OpenCode.

The plan must be practical enough that another AI coding agent can execute it task-by-task without needing to redesign the product again.

---

# 47. OUTPUT QUALITY BAR

This is an **enterprise product architecture document**, not a generic feature list.

I expect:

- deep reasoning
- concrete architecture
- complete feature coverage
- dependency analysis
- permission analysis
- tenant analysis
- workflow analysis
- subscription analysis
- database analysis
- integration analysis
- migration strategy
- implementation sequencing
- testing strategy
- security analysis
- scalability analysis

Do not produce vague statements such as:

> “Add advanced HR features.”

Instead say exactly:

> “Introduce a reusable Employee Lifecycle Workflow Engine supporting configurable states, dynamic approvers, effective dates, transition validation, notifications, audit events, and tenant-level workflow templates. Use it for onboarding, confirmation, transfer, promotion, and offboarding.”

Every recommendation should be this concrete.

---

# 48. IMPORTANT: SOURCE-BASED ANALYSIS

Before producing the final plan:

1. Read ALL attached documents.
2. Cross-reference them.
3. Identify contradictions.
4. Identify duplicated functionality.
5. Identify stale documentation.
6. Identify implemented functionality.
7. Identify deferred functionality.
8. Identify reserved functionality.
9. Identify missing functionality.
10. Identify architectural risks.
11. Identify technical debt.
12. Only then create the roadmap.

Do not produce the roadmap before completing this audit.

The final document should make it possible for me to understand:

**WHERE WE ARE → WHERE WE SHOULD GO → WHAT IS MISSING → WHAT MUST CHANGE → WHAT TO BUILD FIRST → HOW TO BUILD IT SAFELY.**