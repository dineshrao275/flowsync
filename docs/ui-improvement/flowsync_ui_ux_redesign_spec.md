# FlowSync — Master UI/UX Redesign Specification (All Screens & Components)

> **Design Alignment**: Every screen, modal, drawer, dialog, popover, and dropdown across FlowSync is redesigned to align with the warm, refined, and tactile craftsmanship of **Claude AI**. The system pairs warm ivory and deep obsidian surfaces, subtle hairline borders, and responsive micro-interactions with absolute parity across **Light Mode, Dark Mode, and Built-In Customization Themes**.
>
> ⚠️ **Status**: Pure Design Specification. **No code changes have been made** per user instructions.

---

## 1. Complete Visual Gallery & High-Fidelity Mockups

````carousel
![1. TMS Project Hub & Agile Kanban Board (Light Theme)](/home/dineshrao/.gemini/antigravity-ide/brain/f89f23fe-3a9a-4400-b1ad-15826947c5b6/tms_kanban_board_1791610036845.jpg)
<!-- slide -->
![2. Interactive Task Drawer with Checklists & Activity (Dark Theme)](/home/dineshrao/.gemini/antigravity-ide/brain/f89f23fe-3a9a-4400-b1ad-15826947c5b6/task_detail_dark_1791610057607.jpg)
<!-- slide -->
![3. HRMS Executive Hub, Clock-In Widget & Attendance (Light Theme)](/home/dineshrao/.gemini/antigravity-ide/brain/f89f23fe-3a9a-4400-b1ad-15826947c5b6/hrms_overview_hub_1791610080803.jpg)
<!-- slide -->
![4. Employee 360 Profile & Org Hierarchy Visual Tree (Light Theme)](/home/dineshrao/.gemini/antigravity-ide/brain/f89f23fe-3a9a-4400-b1ad-15826947c5b6/employee_org_profile_1791610521033.jpg)
<!-- slide -->
![5. Modular Subscription, Plans & Billing Hub (Light Theme)](/home/dineshrao/.gemini/antigravity-ide/brain/f89f23fe-3a9a-4400-b1ad-15826947c5b6/subscription_billing_hub_1791610544547.jpg)
<!-- slide -->
![6. Unified Approvals Inbox & Support Desk (Light Theme)](/home/dineshrao/.gemini/antigravity-ide/brain/f89f23fe-3a9a-4400-b1ad-15826947c5b6/unified_inbox_support_1791610573832.jpg)
<!-- slide -->
![7. Super Admin Platform & System Analytics Dashboard (Light Theme)](/home/dineshrao/.gemini/antigravity-ide/brain/f89f23fe-3a9a-4400-b1ad-15826947c5b6/platform_analytics_dashboard_1791610497522.jpg)
<!-- slide -->
![8. Design System UI Kit: Modals, Popovers, Drawers & Inputs (Light & Dark)](/home/dineshrao/.gemini/antigravity-ide/brain/f89f23fe-3a9a-4400-b1ad-15826947c5b6/ui_components_spec_1791610109469.jpg)
````

---

## 2. Global Design Tokens & Theming Parity

### 2.1 Thematic Color Architecture (Light vs. Dark vs. Custom)

| Token Key | CSS Variable | Light Theme (Warm Ivory) | Dark Theme (Obsidian) | Role / Usage |
| :--- | :--- | :--- | :--- | :--- |
| **Canvas Background** | `--dashboard-bg` | `#FBFBFA` (Warm Ivory) | `#131720` (Obsidian Canvas) | Root background |
| **Surface Level 1** | `--card-bg` | `#FFFFFF` (Crisp White) | `#1A202C` (Deep Slate) | Cards, column containers |
| **Surface Level 2** | `--surface-elevated` | `#F4F3EF` (Toned Sand) | `#232B3A` (Elevated Layer) | Hover states, pill containers |
| **Surface Level 3** | `--surface-overlay` | `#FFFFFF` | `#2A3446` (Modal/Drawer) | Modals, drawers, dropdowns |
| **Hairline Borders** | `--border-hairline` | `#E8E7E3` (Subtle Umber) | `#2F3A4C` (Muted Slate) | 1px clean dividers |
| **Primary Text** | `--text-primary` | `#1C1917` (Deep Charcoal) | `#F8FAFC` (Warm White) | Headings, primary labels |
| **Secondary Text** | `--text-secondary` | `#57534E` (Muted Umber) | `#94A3B8` (Soft Slate) | Subtext, timestamps |
| **Tertiary Text** | `--text-tertiary` | `#A8A29E` (Warm Stone) | `#64748B` (Muted Stone) | Placeholders, inactive icons |
| **Brand Accent** | `--accent` | `#4F46E5` (Dynamic) | `#6366F1` (Dynamic) | Primary action CTAs |
| **Accent Soft Tint** | `--accent-soft` | `#EEF0FE` | `rgba(99, 102, 241, 0.15)`| Active tabs, tag fills |
| **Status: Todo** | `--status-todo` | `#64748B` | `#94A3B8` | Backlog / Planned status |
| **Status: In Progress**| `--status-progress`| `#D97706` | `#FBBF24` | Active tasks, Clocked in |
| **Status: Done** | `--status-done` | `#059669` | `#34D399` | Completed, Approved |
| **Status: Blocked** | `--status-blocked` | `#E11D48` | `#FB7185` | Blocked tasks, Rejections |

### 2.2 Built-In Preset Palettes
- **Indigo Slate (Default)**: Deep blue-slate sidebar (`#0F172A`), indigo accent (`#6366F1`), ivory dashboard (`#FBFBFA`).
- **Emerald Dark**: Deep spruce sidebar (`#022C22`), emerald accent (`#10B981`), mint-tinted dashboard.
- **Rose Night**: Warm graphite sidebar (`#1C1917`), coral accent (`#F43F5E`), blush-tinted dashboard.
- **Blue Steel**: Marine navy sidebar (`#0C4A6E`), cyan accent (`#0284C7`), frost-tinted dashboard.
- **Custom Mode**: Real-time HSL color pickers for Sidebar, Active Menu, Accent, Header, and Cards via `ThemeSettingsDrawer.jsx`.

---

## 3. Screen-by-Screen Redesign Catalog

### Module 1: Core Navigation & Global Shell
1. **Sidebar Navigation (`Sidebar.jsx`)**:
   - Collapsible dual-state (Icon Rail 64px ⟷ Full Tree 256px).
   - Tenant switcher dropdown header with company logo and plan badge.
   - Pinned bottom actions: Theme toggle (Light/Dark/System), User profile card, Impersonation stop trigger.
2. **Topbar (`Topbar.jsx`)**:
   - Animated breadcrumbs linking Workspace ➔ Project ➔ Sub-view.
   - Global Search trigger with `⌘K` keyboard shortcut badge.
   - Impersonation Banner: High-visibility glowing amber bar with `Exit Impersonation` button.
   - Notification Bell with unread counter and slide-out notification drawer.
3. **Command Palette (`Search.jsx`)**:
   - Floating centered modal with blurred backdrop (`backdrop-blur-md`).
   - Unified search querying Tasks, Employees, Projects, Docs, and Navigation shortcuts.

---

### Module 2: Task Management Suite (TMS)
4. **Dashboard (`Dashboard.jsx`)**:
   - My Tasks widget (due today, overdue), Sprint Progress burndown, Recent Activity feed, and Project health cards.
5. **Workspaces Catalog (`Workspaces.jsx`)**:
   - Grid cards showing workspace avatar, project count, member avatars, and `+ Create Workspace` card.
6. **Workspace Detail (`WorkspaceDetail.jsx`)**:
   - Tabbed view: Projects grid, Members & roles matrix, Workspace Labels catalog, Settings.
7. **Projects Catalog (`Projects.jsx`)**:
   - Searchable list with Workspace badges, Lead user chip, task completion meters, and health status.
8. **Project Detail Hub (`ProjectDetail.jsx`)**:
   - Segmented view switcher: Kanban Board, Task Table, Sprints & Backlog, Automations, Outbound Webhooks, Workflow Transition Rules, Project Members, and Settings.
9. **Kanban Board (`KanbanBoard.jsx`)**:
   - Sticky column headers with task counters and column action dropdowns.
   - Drag-and-drop task cards with checklist completion progress (`4/5`), priority badges, and story points.
10. **Task Table / List (`TaskTable.jsx`)**:
    - Inline editable rows with column sorting (Key, Title, Status, Priority, Assignee, Due Date, Checklists).
11. **Sprints & Backlog Panel (`SprintsPanel.jsx`)**:
    - Active Sprint header with burndown velocity stats, Backlog collapsible accordion, and `Start Sprint` modal.
12. **Task Detail Drawer (`TaskDetail.jsx`)**:
    - 640px slide-out drawer with tabbed sections: Details, Checklists (P4.9a), Activity timeline, Work logs, and Dependencies.
13. **Create Task Modal (`CreateTaskModal.jsx`)**:
    - Clean form with Project selector, Title, Rich markdown description, Issue type pill, Priority pills, Assignee autocomplete, and Due date picker.
14. **Workflow Transition Editor (`WorkflowRules.jsx`)**:
    - Interactive matrix mapping allowed status transitions (e.g. Backlog ➔ In Progress ➔ Done) with entry validation rules.
15. **Automations Builder (`AutomationPanel.jsx`)**:
    - If-This-Then-That rule builder: Trigger (Status changed, Task assigned) ➔ Conditions ➔ Actions (Notify, Reassign).

---

### Module 3: Human Resource Management Suite (HRMS)
16. **HRMS Overview Hub (`HrmsOverview.jsx`)**:
    - Clock-In/Clock-Out widget with elapsed live timer, Shift badge, Leave balance circular gauges, Attendance calendar, Upcoming holidays card, and Employee quick list.
17. **Employees Directory (`Employees.jsx`)**:
    - Multi-filter table (Department, Location, Role, Employment Status) with bulk action toolbar (`Export CSV`, `Bulk Update`).
18. **Employee 360 Profile (`EmployeeDetail.jsx`)**:
    - Left summary card (Photo, Title, Manager, Direct reports) + Right tabs: Overview, Org Tree node, Documents, Assigned TMS Tasks, Attendance & Leaves, Compensation.
19. **Organizational Hierarchy Tree (`Org.jsx`)**:
    - Pan/zoom visual hierarchy chart with departmental drilldowns and profile hover popovers.
20. **Attendance Hub & Approvals (`Attendance.jsx`, `AttendanceApprovals.jsx`)**:
    - Monthly punch matrix, daily clock-in/out stamps, break logs, and Manager Override Approval drawer.
21. **Leave Management & My Leave (`Leave.jsx`, `MyLeave.jsx`)**:
    - Accrual balance cards, upcoming team leave calendar, blackout date alerts, and `Request Leave` modal.
22. **Comp-Off Hub (`CompOff.jsx`, `MyCompOff.jsx`)**:
    - Credit balance tracker, overtime work-log verification, and claim approval flow.
23. **Company Holidays (`Holidays.jsx`)**:
    - Multi-location holiday cards, calendar view, and `Import Holidays` modal with CSV sample.
24. **Shifts & Rosters (`Shifts.jsx`)**:
    - Shift catalog (Day, Evening, Night, Flexible) and weekly employee rotation schedule grid.
25. **Payroll Runs & Payslips (`Payroll.jsx`, `MyPayslips.jsx`, `PayrollRunDetail.jsx`)**:
    - Payroll pipeline (Draft ➔ Approved ➔ Disbursed), salary slip preview with tax deductions, and PDF download button.
26. **Expenses & My Expenses (`Expenses.jsx`, `MyExpenses.jsx`)**:
    - Claim submission modal with receipt image preview, category breakdown, and reimbursement approval queue.
27. **Performance & OKRs (`Performance.jsx`, `MyPerformance.jsx`)**:
    - Goal progress cards, 1:1 check-in notes thread, and 360 appraisal review questionnaires.
28. **Company Assets (`Assets.jsx`, `MyAssets.jsx`)**:
    - Hardware/software inventory, serial numbers, warranty countdowns, and Asset Replacement modal.
29. **Document Center (`Documents.jsx`, `MyDocuments.jsx`)**:
    - Document category folders, version history modal, and e-signature verification badges.
30. **Unified Approvals Inbox (`Inbox.jsx`)**:
    - Centralized queue consolidating Leave requests, Expense claims, Attendance adjustments, and Asset tickets with one-click `Approve` / `Reject`.
31. **Onboarding & Offboarding Cases (`OnboardingCases.jsx`, `OffboardingCases.jsx`)**:
    - Multi-stage onboarding checklists, asset provisioning, IT access grants, and exit interview clearance.
32. **HR Analytics & Workforce Audit (`Analytics.jsx`, `AuditLog.jsx`)**:
    - Headcount growth chart, attrition rate gauge, departmental distribution, and immutable HR audit log.

---

### Module 4: Tenant Administration & Settings
33. **Settings (`Settings.jsx`)**:
    - General organization profile, domain settings, custom fields manager, and theme configuration.
34. **Theme Customization Drawer (`ThemeSettingsDrawer.jsx`)**:
    - Slide-out palette tuner with light/dark/system mode toggles, 4 preset swatches, and custom color pickers.
35. **Users & Permissions (`Users.jsx`, `UserEdit.jsx`, `UserImport.jsx`)**:
    - Member table with role badges, ceiling-checked bulk CSV import wizard, and individual edit modal.
36. **Roles & RBAC Matrix (`Roles.jsx`)**:
    - Interactive granular permission matrix + "Why can't they?" access explainer popover.
37. **Subscription & Plans (`Subscription.jsx`, `Plans.jsx`)**:
    - Plan tiers comparison, separate TMS and HRMS module toggles, seat quota meter, payment method chip, and invoice history table.
38. **Data Export (`DataExport.jsx`)**:
    - Full tenant export generator with signed download links and automated backup schedules.
39. **Support Tickets (`Support.jsx`)**:
    - Tenant support ticket list, thread detail drawer, priority badges, and attachment upload.
40. **Outbound Webhooks (`Webhooks.jsx`)**:
    - Webhook endpoint configuration, event trigger subscriptions, secret signing keys, and delivery logs viewer.

---

### Module 5: Super Admin & Platform Operations
41. **System Dashboard (`SystemDashboard.jsx`)**:
    - Central multi-tenant dashboard: Total ARR, Active Tenants counter, System health gauge, and API latency charts.
42. **Tenants Management & Detail (`Tenants.jsx`, `TenantDetail.jsx`)**:
    - Tenant fleet table with physical DB connection status, subscription status, and tenant overview cards.
43. **Tenant Intake Wizard (`TenantIntake.jsx`)**:
    - 4-step intake flow: Company Info ➔ Product Selection (TMS / HRMS) ➔ Database Provisioning ➔ Admin Setup.
44. **Platform Analytics (`SystemAnalytics.jsx`)**:
    - Fleet growth charts, tenant storage utilization, and active user distribution.
45. **Platform Users & Audit (`SystemUsers.jsx`, `AuditLogs.jsx`)**:
    - Super admin access management and cross-tenant immutable audit trail with diff viewer.
46. **Feature Flags (`FeatureFlags.jsx`)**:
    - Boolean and percentage rollout toggles for new modules and beta features.
47. **Super Admin Support Desk (`AdminSupport.jsx`)**:
    - Central helpdesk queue with internal admin notes and tenant impersonation quick link.

---

### Module 6: Authentication & Onboarding
48. **Login & Registration (`Login.jsx`, `Register.jsx`, `RegisterComplete.jsx`)**:
    - Centered minimalist card layout with warm ivory backdrop, clear inputs, social/SSO triggers, and branded badge.
49. **Password Recovery (`ForgotPassword.jsx`, `ResetPassword.jsx`)**:
    - Clean recovery flow with rate-limit indicators and email confirmation feedback.
50. **Tenant Onboarding Wizard (`Onboarding.jsx`)**:
    - Guided step-by-step setup: Workspace naming ➔ Product configuration ➔ Card on file trial setup ➔ Team invitations.

---

## 4. Universal Interactive Components & States

### 4.1 Modals & Dialogs
- **Entrance Animation**: Smooth spring-scale entrance (`scale-95 opacity-0 -> scale-100 opacity-100` over 180ms).
- **Backdrop**: Deep frosted blur (`bg-stone-900/40 backdrop-blur-md`).
- **Focus Trap**: Traps keyboard focus, closes on `Escape` key or backdrop click.
- **Header & Footer**: Fixed header with clear title/subtitle; sticky action footer with Cancel (Ghost) and Confirm (Solid).

### 4.2 Slide-Out Drawers
- **Width**: Standard 480px, Wide 640px, Extra-Wide 800px.
- **Surface**: Pure elevated background with left hairline border and soft deep shadow (`shadow-2xl`).
- **Close Behavior**: Backdrop tap, `Escape` key, or top-right `✕` trigger.

### 4.3 Dropdowns, Popovers & Menus
- **Positioning**: Adaptive collision detection (flips when close to viewport edge).
- **Item States**: Default, Hover (subtle warm background), Active/Selected (accent checkmark + bold label).

### 4.4 Form Controls & Buttons
- **Buttons**:
  - `Primary`: Solid high-contrast fill with subtle active scale down (`active:scale-[0.98]`).
  - `Subtle`: Muted stone background with crisp text.
  - `Ghost`: Clean borderless button with hover highlight.
  - `Danger`: Rose fill with crimson text for destructive confirmations.
- **Inputs & Selects**:
  - Warm 1px hairline border, subtle inner shadow, and 2px accent focus ring without harsh outlines.
