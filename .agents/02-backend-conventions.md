# 02 — Backend Conventions

## Layering (mandatory shape for new endpoints)

`Http/Requests/<Name>Request` (validate) → Controller (validate → authorize → **one**
service call → present) → `Services/<Context>/` (rules) → Presenter in the same
service folder (response shape) → Policy (per-record answers). State is always a backed
enum in `app/Enums/` (`Hrms/` subdir for HRMS), hex `color()` when rendered.

- Controllers do NOT contain business rules; services do NOT build HTTP responses.
- Set-level scoping (`casesFor`/`indexFor`/`visibleTaskQuery`) lives in **services**;
  per-record answers live in **policies**. Both read the same permissions so they
  cannot disagree (established pattern: P4.3/P5.4/P13.3).
- Writes use FormRequests in `app/Http/Requests/` (`Hrms/` subdir). Controllers
  type-hint the request and use `$request->validated()`.
- Pagination payloads are uniform `{current_page,last_page,per_page,total}` —
  never `->toArray()`.
- Helpers: `utils/format.js` (`formatDate/formatDateTime/formatPrice`),
  `deepLinks.js`, `notifications.js`, `time.js`.

## HTTP surface rules

- HRMS controllers live in `app/Http/Controllers/Hrms/` (flat root, matching this
  repo — NOT the plan doc's `Api/Hrms/` path). Behind `ensure_module:hrms.core` +
  `permission:hrms.view` at minimum, per-record policy decides rows.
- Every collab/task method signature MUST declare `Project $project` alongside
  `Task $task` — Laravel binds `{project}`/`{task}` from the **controller signature**;
  omitting `Project` leaves a raw string spliced positionally → TypeError.
- Policy array form: `authorize('create', [Comment::class, $task])` so the policy
  resolves to the right class.
- Foreign nested ids are verified to belong (foreign task id → 404); `{workLog}`
  verified `task_id === $task->id`.
- `reorder`-style literal routes declared BEFORE their `{resource}` sibling
  (`departments/reorder` after `departments/{department}` binds "reorder" as an id).
- Field-shaped errors keyed to the field (`head_employee_id` → picker); structural
  errors (cycle, in-use) keyed to `form`.
- `sometimes` must NEVER precede a conditional `required` (`sometimes` skips remaining
  rules when the key is absent — kills `Rule::requiredIf`).
- Boolean query input goes through `NormalizesBooleanInput` (axios serializes
  `trashed=false` into the URL; raw `"false"` casts to **true** in Eloquent;
  Laravel's `boolean` rule rejects `"true"`/`"false"` strings).

## Signed, tenant-scoped downloads (the one pattern for ALL file serving)

Never `Storage::url()`. The central tenant id rides **inside** a `signed` route
OUTSIDE `switch_tenant`; the controller takes raw ints, resolves
`Tenant::find($request->query('tenant'))` on the central connection, and streams
inside `TenantDatabaseManager::using()`. For confidential rows the reader id also
rides in the signature (session-free requests have no other identity). Every such
endpoint ships a `flushSession()` + `DB::setDefaultConnection('iso_system')`
regression test. Exemplars: `AttachmentController::download`,
`PayslipDownloadController` (the good auth pattern: anonymous denied, self-or-`view_all`).

## PHP / Eloquent gotchas (each paid for at least once)

- `constrained()` inside `Schema::table()` rebuilds sqlite tables via `__temp__` copy
  and doctrine drops column-level CHECKs → add FK columns with raw
  `alter table … add column … references …` (grammar-quoted via
  `Schema::getConnection()->getQueryGrammar()->wrap()`, NEVER backticks — PG 42601s
  them) + separate index; `down()` drops index before column.
- Chain `unique()` BEFORE `constrained()` on a `foreignId` — modifiers after
  `constrained()` are silently dropped (no unique index, no error).
- `Schema::getColumns()` spells "no default" two ways: sqlite OMITS the key, PG
  returns `null`. Test `! array_key_exists('default', $c) || $c['default'] === null`,
  on both grammars.
- Carbon 2/3 diffs are signed floats: wrap in `abs()` and int-cast
  (`diffInSeconds`, `diffInYears`, pairing/lateness math).
- Date-cast columns serialize with a time part on sqlite — date lookups use
  `whereDate`, never exact-string `where` or `Y-m-d` `whereBetween`.
- Read `QueryException::errorInfo[2]`, never `getMessage()`, to identify a unique
  violation (the message embeds the statement; `employees` inserts always name
  `employee_code`).
- `makeDefault()`-style flag shifts go through the query builder, not
  `$model->update()` (Eloquent dirty-check no-ops when already default → clears
  without re-setting).
- Order activity feeds by `id` desc, never `created_at` alone (second-precision ties).
- 300-line class ceiling per the HRMS plan: split bounded contexts into folders
  (D2.16.2) instead of trimming comments — `Employee/`, `Org/`, `Document/` precedent.
- No `docs/hrms-architecture.md`: the HRMS plan (Parts 1–3) + `AGENTS.md` are the record.
