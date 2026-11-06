# AI assistant: architecture and design (Phase 1)

Status: **implemented on `feat/ai-assistant` (Phases 2-5), not merged or deployed.** Sections 1-12 are the design the build followed; section 13 onward is the operating guide and what was actually built, including where it differs from the design. Nothing has been run against a real provider yet: the adapters are verified against recorded wire formats with `Http::fake`, and `php artisan ai:ping --tools` is the live check to run once a key is available.

Core principle: FlowSync owns authorization, retrieval, business rules and execution. A hosted model supplies language and reasoning through a replaceable adapter. The model never decides what a user may see, and unauthorized data never reaches it.

## 1. Verified starting point

Nothing in `app/` or `config/` references an LLM. Everything below was read from the code, not assumed.

| Concern | Existing piece to reuse |
|---|---|
| Gateway/adapter pattern | `app/Billing/*` (`PaymentGateway`, `Gateways/*`, `FakePaymentGateway`, `PaymentResolver`), `app/Services/Integrations/Git` (`FakeGitProvider`) |
| Scope-aware permission check | `User::granted($slug)`, `app/Support/PermissionScope.php` (own, assigned, all) |
| TMS row scoping | `TaskScope`, `Controllers/Concerns/ScopesVisibleTasks::visibleTaskQuery()`, `Services/Tasks/TaskReader::filteredQuery()`, overdue rule in `DashboardController` |
| HRMS row scoping | `App\Services\Hrms\HrmsScope` (`widestView`, `employeeIdsFor`, `coversEmployee`), directories such as `LeaveRequestDirectory` |
| Existing search | `GlobalSearchController` (`searchTasks`, `searchProjects`, `searchEmployees`), `SearchController::tasks` |
| Task creation | `TaskController::store` then `TaskService::create`, custom fields, `TaskChangeLogger::created` |
| Entitlements | `config/subscriptions.php` modules, `TenantLimits::hasModule/limit/assertQuota`, `ensure_module:` middleware |
| Audit | `ActivityLogger` (also emits domain events), `HrmsAuditLogger::accessed` |
| Uploads | `AttachmentStoreRequest` type allowlist, `UploadScanner::assertSafe` |
| Queues | tenant stamping via `Queue::createPayloadUsing` and `SwitchesTenantConnectionForQueuedJobs` |

Gaps that shape the design:

- `TaskService::create` has **no transaction** and no sprint argument. Custom fields and the change log are written by the controller afterwards. An AI accept path must repeat all of it.
- `EnforceIdempotency` only applies to API-token requests. Session calls need their own dedupe (proposal id plus item index).
- There is no task duplicate detection, only trigram search indexes.
- There is no SSE in the SPA, and `services/api.js` hard-redirects on 401/402/403/503, which would break a stream.
- No PDF or DOCX parser is installed.
- `TenantLimits` is frozen by the class-size ratchet, so AI counters live in a new service.

## 2. Components (`App\Services\Ai`, config `config/ai.php`)

```
Contracts/AiProvider        capabilities(), complete(AiRequest), stream(AiRequest)
Dto/                        AiRequest, AiMessage, AiToolSpec, AiToolCall, AiResponse, AiChunk, AiUsage, AiCapabilities
Providers/                  GeminiProvider, GroqProvider, FakeProvider
AiProviderResolver          AI_PROVIDER -> adapter (mirrors PaymentResolver)
AiConfigurationService      validates provider, key, model, capabilities; no-op when AI_ENABLED=false
AiResponseNormalizer        provider payload/error -> internal DTOs / AiProviderException kinds
Tools/AiToolRegistry        exposes only tools the caller may use; one class per tool
AiOrchestrator              model -> tool calls -> tool results -> answer, bounded loop
AiConversationService       conversations/messages, owner-only
AiUsageService              per-tenant counters, limits, cost estimate
AiProposalService           pending action proposals, confirm/reject, idempotent execution
AiAuditLogger               wraps ActivityLogger, stamps via=ai
```

Each class stays under the 300-line ceiling. Controllers only validate, authorize, make one service call and present.

### Provider contract and capabilities

`AiCapabilities`: `text`, `streaming`, `tools`, `parallel_tools`, `json_schema_output`, `documents`, `context_window`. Each adapter reports its own values per configured model. `AiConfigurationService` compares them with what a feature needs (chat needs `text`; search needs `tools`; document proposals need `json_schema_output` or a validated JSON-mode fallback). A missing capability produces a typed error and a clear message in the UI. It never switches provider silently. `AI_FALLBACK_PROVIDER` is optional, explicit, and off by default.

Known capability differences already visible in the docs:

- Groq: structured outputs (strict) and tool use cannot be combined in one request, and gpt-oss models do not support parallel tool calls. Orchestrator therefore does tool rounds and the final structured extraction as separate calls.
- Gemini: structured output combined with function calling is documented for the Gemini 3 series only.
- Document input to the model is treated as an optional capability. FlowSync extracts text itself, so the workflow works with every provider.

### Normalization

Adapters translate to and from the internal DTOs only. Tool specs are JSON Schema in the registry; each adapter maps them to its wire format (Gemini function declarations, OpenAI-style `tools` for Groq). Tool results go back as internal `AiMessage(role=tool)`. Errors map to `rate_limit`, `timeout`, `auth`, `unsupported`, `bad_output`, `unavailable`. Retries use backoff only for read-only model calls and never replay a tool execution.

## 3. Configuration

```dotenv
AI_ENABLED=false
AI_PROVIDER=gemini            # gemini | groq | fake
AI_MODEL=
AI_API_KEY=                   # or GEMINI_API_KEY / GROQ_API_KEY (per-provider wins when set)
AI_BASE_URL=                  # optional override
AI_TIMEOUT_SECONDS=60
AI_MAX_RETRIES=2
AI_MAX_OUTPUT_TOKENS=4096
AI_STREAMING_ENABLED=true
AI_TOOL_CALLING_ENABLED=true
AI_DAILY_REQUEST_LIMIT=       # platform default per tenant, plans override
AI_DAILY_TOKEN_LIMIT=
AI_FALLBACK_PROVIDER=         # empty = none
```

Switching providers means editing `AI_PROVIDER`, `AI_MODEL` and the key, then `php artisan config:clear` (or recache) and restarting the queue worker and Reverb units so long-lived processes reload env. No source change. Keys are read server-side only and never serialized to the SPA; `GET api/ai/status` returns `enabled` and capability flags, not provider secrets.

## 4. Provider evaluation (read 2026-11-06 from official docs)

| | Gemini API | Groq |
|---|---|---|
| Free tier | Yes, on Flash/Flash-Lite models (e.g. `gemini-3.1-flash-lite`, `gemini-3.5-flash-lite`). Per-model quotas are on a dynamic AI Studio page, **not verified** | Yes. Table read shows `openai/gpt-oss-120b` at 30 RPM, 1K RPD, 8K TPM, 200K TPD, but the page does not label the plan, **not verified** |
| Paid price (per 1M in/out) | `gemini-3.1-flash-lite` $0.25/$1.50; `gemini-3.5-flash-lite` $0.30/$2.50; `gemini-3.8-flash` $0.75/$3.75 until 2026-12-31 | `gpt-oss-120b` $0.15/$0.60; `gpt-oss-20b` $0.075/$0.30; `qwen3.8-27b` $0.80/$4.00 |
| Tool calling | Yes; parallel and sequential; documented format uses the newer Interactions API | Yes, OpenAI-compatible; parallel not on gpt-oss |
| Structured output | JSON schema | `json_schema` strict on gpt-oss and qwen; not combinable with tools or streaming |
| Documents | PDF to 50 MB / 1000 pages, DOCX and others | Images only on listed models; PDF not confirmed |
| Context | not stated on pages read | 131,072 on the main models |
| Data terms | **Free tier: content used to improve Google products and may be read by human reviewers. Paid: not used for improvement, logged for a limited period.** | **Not verified** (docs URL returned 404) |

Decision: start with **Gemini** (`AI_MODEL` set to a Flash-Lite id after a live test call confirms tool calling) and add **Groq** as the second adapter to prove switching. Both stay behind the contract.

Open items that must be closed before the adapters are final (each is a small spike with a throwaway key):

1. Confirm which Gemini endpoint to code against. The pages read describe `v1beta/interactions`; `generateContent`/`streamGenerateContent` were not on any page read. Pick the one that is documented as stable for the chosen model and pin the revision header.
2. Confirm Groq streaming format and retrieve its data-retention/training terms from the correct docs page.
3. Obtain current free-tier quotas for the chosen models from the dashboards.

**Privacy gate:** the Gemini free tier may use content to improve products and allows human review. Until a paid tier or an equivalent no-training agreement is confirmed for the chosen provider, development and tests use synthetic data only (the `acme`/`globex` seeds and the Fake provider). Real tenant data does not go to a free tier. `AiConfigurationService` should refuse `APP_ENV=production` with a provider flagged `free_tier` unless `AI_ALLOW_FREE_TIER=true` is set explicitly.

## 5. Trust boundaries and threat model

Data flow: browser -> authenticated session route (`switch_tenant`, `auth`, `tenant`, `tenant_context`, `ensure_module:ai`, `permission:ai.use`, throttle) -> `AiOrchestrator` -> provider adapter -> model -> tool calls -> `AiToolRegistry` -> existing scoped services -> pre-filtered DTO -> model -> reply.

| Threat | Control |
|---|---|
| Model asks for data the user cannot see | Tools run as the requesting user through `granted()`, `HrmsScope`, `visibleTaskQuery`, policies. Rows are filtered in the query, so they never enter the prompt. |
| Cross-tenant leakage | Tools execute on the request's tenant connection. Conversations, messages, proposals, caches and document files are tenant-DB tables or tenant-keyed cache entries. No cross-tenant fan-out tool exists. |
| Forged or tampered tool arguments | Arguments validated against the tool's JSON Schema and again by a FormRequest-style rule set. Ids are resolved through scoped queries, never trusted. |
| Prompt injection in documents, comments, task text | All retrieved or uploaded text is wrapped as untrusted data in a delimited block. Tools available in that turn do not include write tools. Writes happen only through the confirm endpoint, which takes a stored proposal id, never model output. |
| Model tries a consequential action | Write tools only create a **proposal**. Confirmation is a separate user-initiated request that revalidates permissions. |
| Stale permissions | Confirm re-runs `createTask` policy and per-field resolvers at execution time. Permission changes apply to the next tool call because nothing is cached across turns. |
| SQL/shell exposure | No tool takes SQL, shell, file paths, or URLs. Tools are a fixed catalog. |
| Prompt/PII in logs | `LogHttpRequests` skips input for `api/ai/*`. Structured logs carry provider, model, latency, token counts, tool names, never message text. |
| Secrets in the browser | Provider keys only in server env. The SPA calls FlowSync endpoints only. |
| Cost abuse | Per-user/tenant rate limiter, per-tenant daily request/token limits, output token cap, bounded tool loop. |
| Provider outage | Typed errors, timeouts, breaker after repeated failures; HRMS/TMS routes do not depend on the AI layer. |

## 6. Tool catalog v1

Registry filters by module, product and permission before the model sees a tool.

TMS (`ensure_product:tms`): `search_tasks` (status, priority, assignee=me, overdue, due range, project, text; page size capped), `get_task`, `list_projects`, `list_workspaces`, `project_summary`, `task_comments`, `task_activity`, `my_time_summary`.

HRMS (`ensure_product:hrms`, each behind its module and scope): `my_leave_balance`, `leave_requests` (scoped via `LeaveRequestDirectory`), `pending_approvals` (via approval engine `canAct`), `my_attendance`, `expense_claims`, `documents_metadata` (no file content, confidential rows invisible without `hrms.documents.view_sensitive`), `assets`, `my_payslips` (own only, existing masking and access rows), `goals`.

Navigation: `navigate_help` returns static, localized route hints from `utils/nav`-equivalent server data. Links in results are built server-side from route helpers mirroring `resources/js/utils/deepLinks.js`.

Write tools (Phase 4 and 5): `propose_tasks` (stores a proposal), later `propose_task_update`, `propose_assignment`, `draft_leave_request`. None executes.

Sensitive HRMS reads write the same `accessed()` rows as the UI path, with `via=ai`.

## 7. Data model

Tenant DB (`database/migrations/tenant_tms`, guarded with `hasTable`):

- `ai_conversations`: id, user_id, title, context (route/product), last_message_at, timestamps. Owner-only access.
- `ai_messages`: conversation_id, role, content (text), tool_name nullable, tool_payload JSON nullable, usage tokens, timestamps. Tool payload stores ids and counts, not full record dumps.
- `ai_documents`: id, user_id, project_id, disk path, original name, mime, size, status, extracted_text_path, expires_at.
- `ai_proposals`: id (uuid), conversation_id, user_id, kind, project_id, payload JSON, status (draft, confirmed, partial, failed, rejected), result JSON, confirmed_at. Items carry a stable `item_index`; created task ids are recorded per item so a retried confirm skips finished items.

System DB: `ai_usage` (tenant_id, day, requests, input_tokens, output_tokens, document_pages, cost_estimate), unique on tenant and day.

Retention: conversations and extracted document text are purged by a scheduled prune after a configurable window; the audit trail remains.

## 8. Endpoints (new `routes/web/ai.php`)

- `GET api/ai/status`: enabled, capabilities, remaining quota.
- `POST api/ai/chat`: SSE stream (`text/event-stream`) with events `token`, `tool`, `result`, `proposal`, `done`, `error`; non-stream JSON when streaming is off or unsupported. Response sets `X-Accel-Buffering: no`.
- `GET|DELETE api/ai/conversations[/{id}]`: own only, other users' ids return 404.
- Phase 4: `POST api/ai/documents`, `POST api/ai/proposals`, `PUT api/ai/proposals/{id}`, `POST api/ai/proposals/{id}/confirm`, `POST api/ai/proposals/{id}/reject`.

Module `ai` and permissions `ai.use`, `ai.manage` are added to `config/subscriptions.php` and `config/permissions.php`. Plan limit keys cover daily requests, daily tokens and document pages. Backend enforcement is by middleware and `AiUsageService`; the SPA only hides UI.

## 9. Frontend

Topbar button next to the theme control, state owned by `AdminLayout`. `components/assistant/AssistantPanel` is an always-mounted `aside` (the `ThemeSettingsDrawer` pattern, not the modal `Drawer`), expandable to a `/assistant` workspace route. `hooks/useAssistantStream` streams with `fetch` and a reader, reuses the CSRF and cookie settings from `services/api.js`, aborts on unmount, and handles 401/402/403 locally instead of triggering the global redirects. Results render as cards with links from `deepLinks.js`. Proposal preview is an editable table; confirm uses `useConfirm`. Strings live in `en` and `es`; `prefers-reduced-motion` disables the slide animation. Hidden unless `hasModule('ai')` and `can('ai.use')`.

## 10. Testing strategy

- Unit and feature tests use `FakeProvider` (scripted responses, captures every outgoing request so tests can assert what the model was sent).
- Adapters are tested with `Http::fake` fixtures: request shape, tool-call translation, streaming parse, error normalization.
- Switching test: run one scripted conversation against Fake, Gemini fixture and Groq fixture with the same orchestrator and tools.
- Security matrix: `{own, assigned, all}` scopes across TMS and HRMS tools, cross-tenant ids, revoked permission mid-conversation, module off, unauthorized tool call, injected document text, sensitive fields absent from the captured prompt.
- Reliability: invalid config, timeout, 429, concurrent requests with two tenants, AI disabled leaves unrelated routes unaffected.
- Live-provider smoke test is opt-in (`AI_LIVE_TESTS=1`), never part of CI.

## 11. Phases and exit gates

1. **Audit and design** (this document). Gate: approval, plus the three open provider items scheduled.
2. **Provider-agnostic foundation.** Gate: adapter, resolver, config validation, migrations, module and permission wiring, and switching tests green; full suite run because config and middleware change.
3. **Assistant UI and permission-aware search.** Gate: security matrix green, Vitest and lint ratchet unchanged, manual check against dashboard overdue counts.
4. **Document-to-task workflow.** Gate: no creation before confirm, retry idempotent, injection tests green.
5. **Production readiness.** Gate: usage limits, metrics, runbook, full suite, privacy checklist signed off.

Nothing is merged or deployed without explicit approval.

## 12. Known limitations to carry forward

- Free tiers are rate-limited and may use content for training; they are for synthetic data only.
- Provider limits and prices change; the evaluation table is dated and must be re-read before a production choice.
- Streaming through some proxies buffers output; verify Apache and any CDN before relying on it.
- HRMS PII tools return masked values by default; the assistant cannot reveal more than the UI would.


## 13. What was built (and where it differs from the design)

| Area | Where |
|---|---|
| Provider contract, DTOs, capabilities, errors | `app/Services/Ai/{Contracts,Dto,Exceptions}` |
| Adapters (Gemini `generateContent`, OpenAI-compatible for Groq, Fake) | `app/Services/Ai/Providers`, shared transport `HttpAiProvider` (timeouts, retry/backoff, breaker, error normalization) |
| Provider choice and validation | `AiProviderResolver`, `AiConfigurationService`, `config/ai.php` |
| Chat orchestration (bounded model/tool loop, SSE) | `AiOrchestrator`, `AiChatController`, `SseEmitter` |
| Tools (read-only) | `app/Services/Ai/Tools`: `search_tasks`, `get_task`, `list_projects`, `project_summary`, `my_leave_balance`, `leave_requests`, `navigation_help`; registered in `AiServiceProvider` |
| Document to tasks | `Documents/` (extractor, store), `Proposals/` (generator, items, duplicates, editor, confirmer), `AiProposalService` |
| Usage, limits, cost, breaker, retention | `AiUsageService`, `AiUsageReport`, `AiCircuitBreaker`, `ai:prune`, `ai:check`, `ai:ping` |
| SPA | `components/assistant/*`, `pages/Assistant.jsx`, `hooks/useAssistant.js`, `hooks/useDocumentTasks.js`, `services/assistantStream.js` |

Differences from sections 2-9:

- **Conversations/proposals/documents live in the tenant core migration set** (`database/migrations/tenant`), not `tenant_tms`, so an HRMS-only tenant can use the assistant. Task and project ids are plain integers there.
- **`ai` is a product-agnostic module** (like `branding`): `TenantLimits::productOfModule('ai')` is `null`. Individual tools and the document flow are gated by product (`tms`/`hrms`) and by their own modules and permissions.
- **No chat-initiated write tools.** The model can only read. The one write path is the document flow, where the model drafts and a person confirms. `propose_task_update`, assignment and HR-request drafting are not built; the `ai_proposals.kind` column and the confirm/revalidate pattern are where they would go.
- **Structured output is optional per provider.** A provider that cannot enforce a schema is asked for JSON in the prompt and held to the same validation (one retry); this is an application-level strategy, not a provider fallback.
- **Duplicate detection** compares normalised titles and `similar_text` (88%) against tasks the user may see; it is advice, not a block.
- **Text extraction runs inline** on upload and again at draft time, bounded by size, page and character caps. A queued extraction job was not needed at those caps; raise the caps only together with moving extraction onto the queue.

## 14. Configuration reference

| Variable | Default | Meaning |
|---|---|---|
| `AI_ENABLED` | `false` | Master switch. When false the assistant is hidden, `/api/ai/*` answers unavailable, and no AI config is validated. |
| `AI_PROVIDER` | `gemini` | `gemini`, `groq` or `fake`. |
| `AI_MODEL` | provider default | `GEMINI_MODEL` / `GROQ_MODEL` when empty. |
| `GEMINI_API_KEY`, `GROQ_API_KEY` | | Provider keys. Win over `AI_API_KEY`. |
| `AI_API_KEY`, `AI_BASE_URL` | | Generic key/base URL for the ACTIVE provider only. |
| `AI_TIMEOUT_SECONDS`, `AI_MAX_RETRIES`, `AI_RETRY_BASE_MS` | `60`, `2`, `400` | Per call. Only rate-limit, timeout and 5xx are retried. |
| `AI_MAX_OUTPUT_TOKENS`, `AI_MAX_INPUT_CHARS`, `AI_HISTORY_MESSAGES`, `AI_MAX_TOOL_ROUNDS` | `4096`, `8000`, `20`, `4` | Bounds per turn. |
| `AI_STREAMING_ENABLED`, `AI_TOOL_CALLING_ENABLED` | `true` | Narrow a model's capabilities. |
| `AI_DAILY_REQUEST_LIMIT`, `AI_DAILY_TOKEN_LIMIT` | unlimited | Platform default per tenant per day; plan limits `ai_requests_per_day` / `ai_tokens_per_day` (and tenant `limits_override`) win. |
| `AI_REQUESTS_PER_MINUTE` | `20` | Per user and tenant, on chat and proposal drafting. |
| `AI_FALLBACK_PROVIDER` | none | Explicit, opt-in. Used only after a transient failure of the active provider. |
| `AI_ALLOW_FREE_TIER` | `false` | Production refuses a free-tier provider unless true (free tiers may train on prompts). |
| `AI_BREAKER_THRESHOLD`, `AI_BREAKER_WINDOW`, `AI_BREAKER_COOLDOWN` | `5`, `60`, `30` | Outage failures in the window that pause the provider for the cooldown. |
| `AI_DOCUMENT_MAX_KB`, `_MAX_CHARS`, `_MAX_PAGES`, `AI_PROPOSAL_MAX_ITEMS` | `10240`, `60000`, `100`, `50` | Document-to-task bounds. |
| `AI_DOCUMENT_RETENTION_DAYS`, `AI_CONVERSATION_RETENTION_DAYS`, `AI_USAGE_RETENTION_DAYS` | `7`, `90`, `400` | Swept daily by `ai:prune --all`. |

## 15. Switching provider or model (no source change)

1. Edit `.env`: `AI_PROVIDER`, `AI_MODEL` (or leave empty for the provider default) and the matching key (`GEMINI_API_KEY` / `GROQ_API_KEY`).
2. `php artisan ai:check` validates provider, model, key and capabilities and prints every problem at once. It never prints a key.
3. `php artisan config:clear` (or `config:cache` if you cache), then restart the long-running processes so they reload env: Apache/php-fpm, `flowsync-queue`, `flowsync-reverb`.
4. `php artisan ai:ping --tools` makes one synthetic round trip (no tenant data) and checks tool calling.
5. Conversations survive a switch: history is stored as plain text in FlowSync's own format, and tool data is re-read fresh each turn.

If the new model lacks a capability the assistant needs, `ai:check` says so. Structured output is optional; tool calling is required unless `AI_TOOL_CALLING_ENABLED=false` (the assistant then cannot read data).

## 16. Security and tenant-isolation findings (from the tests)

- Tools read only through `VisibleTasks`/`TaskScope`, `LeaveRequestDirectory`/`HrmsScope`; a project role limited to `tasks.view_own` sees only its own rows through the assistant, and the hidden rows never appear in the request sent to the model (asserted by capturing the outgoing request).
- A task the user cannot see answers exactly like one that does not exist; a tool the user may not use is neither offered nor executable, even if the model invents the call.
- Model-supplied tool arguments are validated against the tool's schema; unknown keys and wrong types are rejected.
- Conversations and proposals are owner-only and tenant-DB resident; another user, or another tenant, gets 404.
- Document text is quoted as untrusted data, cannot close its own wrapper, and the drafting call carries no tools; injected instructions can at worst change a draft a person reviews.
- Confirmation re-checks view and create permission, locks the proposal row, records per-item results, and creates nothing for an unselected, rejected or already-created item. Retries never duplicate.
- Prompts and answers are never logged; request logging skips `api/ai/*` input; logs carry provider, model, token counts, tool names and latency.

## 17. Cost and usage

Per-tenant counts (requests, input/output tokens, failures, document pages, estimated USD) are kept per day in the central `ai_usage` table with no text, and `GET /api/ai/status` returns today's use and limits. `/api/metrics` exposes `flowsync_ai_requests_today`, `flowsync_ai_tokens_today{direction}`, `flowsync_ai_failures_today` and `flowsync_ai_cost_estimate_usd_today`. Estimated cost uses `config/ai.php` `pricing` (USD per 1M tokens) and is for monitoring, not billing; an unpriced model counts as 0. A document draft costs roughly the document size in input tokens plus up to `AI_MAX_OUTPUT_TOKENS`.

## 18. Privacy checklist before real tenant data goes to a provider

- [ ] Confirm the provider's data-processing terms for the plan you will pay for. Free tiers may use content to improve products and allow human review.
- [ ] Set `AI_ALLOW_FREE_TIER=false` in production (the default).
- [ ] Confirm retention and logging periods for prompts and responses, and where they are processed.
- [ ] Tell tenants that the assistant sends question text, tool results and uploaded-document text to the configured provider (the document dialog says so).
- [ ] Decide retention: `AI_CONVERSATION_RETENTION_DAYS`, `AI_DOCUMENT_RETENTION_DAYS`.
- [ ] Verify the Groq data-retention terms (the page could not be read when this was written) before enabling Groq for real data.

## 19. Known limitations and next steps

- Not yet exercised against a live provider. Run `ai:ping --tools` for each provider, and confirm Gemini's currently documented endpoint and tool-calling shape (the docs read describe a newer Interactions API; the adapter uses `generateContent`, the long-standing REST shape).
- The assistant cannot change data from chat. Next: `propose_task_update`, assignment and HR-request drafts, reusing the proposal/confirm pattern.
- HRMS tools cover leave only. Approvals, attendance, expenses, documents (metadata) and own payslips are the next tools; each must go through its existing directory/policy and write the same access rows as the UI.
- Duplicate detection is title-based. Embeddings were not added (a second provider capability and a data-residency question).
- Scanned PDFs have no text layer and are refused; OCR is out of scope.
- The circuit breaker state lives in the cache store; with the per-process `array` store it does not span workers.
