# 03 — Frontend Conventions (`resources/js/`)

Entry `main.jsx` (imports `./bootstrap`, StrictMode). `app.js` is unused stock — ignore.
Routing in `App.jsx` (`BrowserRouter basename="/app"`); API base `/api`,
`withCredentials` + `withXSRFToken`; 401 → `/app/login` (`services/api.js`);
`fieldErrors()` helper for server validation.

## Routes & guards

- `GuestRoute`: login/forgot/reset (+ register page). `ProtectedRoute` accepts
  `permission` and `module` props (redirects to `/403`).
- Landing = `homeRouteFor(user)` (`utils/deepLinks.js`): `/admin` for a
  non-impersonating super admin, `/dashboard` otherwise. `/dashboard` renders a
  `<Navigate>` to `/admin` for SAs (old bookmarks).
- `ProtectedRoute` preserves `pathname + search` through the login redirect (deep
  links survive login).

## Providers (`context/`)

- `AuthContext`: `user, theme, loading, login, logout, stopImpersonation, can(),
  hasModule(), check(), refresh`. `can()` true for SA unless impersonating.
  `check()` handles `'permission:slug'` / `'module:name'`. Backend `me()` fills
  `user.permissions` + `user.modules` + `onboarding_complete`.
- `ThemeContext` (live draft + save/reset), `ToastContext` — `useToast()` returns the
  toast **object** (`toast.success/error/info`); never `const { toast } = useToast()`.
- `NotificationContext` (30s poll + Echo `user.{id}`; skipped for non-impersonating SA),
  `BreadcrumbContext` (`useSetCrumbs`), `PageTitle`.
- `hasAccess` strips a `permission:` prefix before comparing against bare slugs
  (P13.4 fix — every HRMS manage button gated `can('permission:…')` never rendered).

## Layout & UI primitives (`components/`)

- `AdminLayout`: only the page-content div is keyed by `location.pathname` (never the
  whole `<Routes>` — remount blink). Animated wrapper is a transform container:
  **all full-screen overlays MUST portal** (`createPortal(…, document.body)`).
  `Modal` + `Drawer` already portal; `CommandPalette` (z-80) and theme drawer render
  outside the animated wrapper.
- `components/ui/`: `Select, Modal, Drawer, EmptyState, Avatar, Spinner, Pagination,
  Table, Badge, Alert, AuthShell, StatRow, fieldStyles` (+ enriched
  `Button, Input, Card`). Inline grid/flex cells use `fieldClass`/`fieldClassCompact`
  directly (`Select` is label-wrapping, unfit for inline cells).
- Sidebar: collapsible (`w-64 ↔ w-16`, localStorage `flowsync.sidebar.collapsed`),
  items filtered by `permission`/`module` (items with no `permission` always show).
  Super-admin sections: Administration (Overview/Tenants/Plans/Features) + Platform
  (Users/Analytics/Audit Logs/Settings).
- Topbar: `NotificationBell` (latest 8, mark-read navigates via `notificationHref`),
  theme button, `⌘K/Ctrl+K` command palette (gated `workspaces.view`).
- `ImpersonationBanner` shifts sidebar (`top:2.5rem`) and sticky topbar (`top-10`).

## Deep links (single source: `utils/deepLinks.js`)

`taskUrl(projectId,key,section)` / `projectUrl(id,tab)` / `workspaceUrl(id,tab)`.
All `?tab=`/`&task=`/`&section=` building lives here — reused by CommandPalette
`hrefFor`, `utils/notifications.js` `notificationHref` (type-aware:
`task.commented` → `section=comments`, `task.work_logged` → `section=time`),
Dashboard rows, Search results. Query stays in the URL (refresh/direct-tab safe);
`ProjectDetail`/`WorkspaceDetail` tabs are URL-driven (`?tab=`, pruned when leaving
Tasks); `TaskDetail` takes `initialSection`. Sections:
`details|comments|attachments|dependencies|time|activity`.

## Page patterns

- Detail pages catch 403 → `navigate('/403', {replace:true})`; other failures keep
  the generic error state.
- Member/status write endpoints return `{message}` only — pages refetch after mutation.
- Manageability mirrors: workspace `can('workspaces.manage') || my_role ∈ {owner,admin}`;
  project `can('workspaces.manage') || my_role === 'lead'`; tasks create/edit
  `lead|developer`, delete tenant-admin-or-lead; time `canLog lead|developer`,
  `canManageLogs lead`.
- Board: dnd-kit (`DndContext` + per-column `SortableContext`, droppable `col-{id}`),
  horizontal `board-scroll` (never wrapping grid); drag posts `{status_id, index}`
  then refetches; subscribes `project.{id}` `.task.synced` → refetch.
- Task drawer sub-tabs fetch separately (comments/attachments/deps/time/activity).
- `EmployeeEditModal` omits the personal block when the record arrived `restricted`
  (never submit masked values back). Manager pickers list everyone, not just
  current managers.
- Recharts for analytics; analytics fetch is best-effort (hidden on failure).
