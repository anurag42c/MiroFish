# Service Call Lifecycle System: Build Specification for Claude Code

Source design: canvas https://claude.ai/artifact/UkVCHFtQbEJCRdMhK3XBjA (14 screens).
Prior art: `SERVICE_PROCESS.html` (React prototype: command centre, live tickets, warranty validation, inventory, engineer mobile view).

**Goal:** a complete system that takes a service call from registration (call-centre agent or AI agent) through dispatch and on-site repair to closure by the service engineer, with SLA control, parts, customer communication and analytics.

**How to use this document:** build phase by phase (section 14). Each phase ends with acceptance tests. Do not start a phase before the previous one passes. Values marked `PROPOSED` are placeholders the business must confirm; keep them in config, never hard-coded.

This is a new, self-contained package. Create it at `apps/service-call/` (or a new repo). It does not depend on the existing MiroFish code.

---

## 1. Scope and roles

| Role | Uses | Main screens |
|---|---|---|
| Call-centre agent | Web console | Intake console (design board 4) |
| AI agent | WhatsApp, voice transcript, API | AI intake (board 5) |
| Dispatcher | Web console | Dispatch board (6) |
| Field engineer | Mobile PWA, offline-first | Jobs, job detail, work, close (8a to 8d) |
| Customer | WhatsApp link, mobile web | Track visit, feedback (10a, 10b) |
| QA reviewer | Web console | Closure and QA queue (7) |
| Region manager / service head | Web console | Supervisor command centre (9) |

Out of scope for v1: invoicing engine and payment gateway (stub with an interface), route optimisation beyond distance ranking, voice telephony (accept transcripts and call events via API).

## 2. Architecture

- **Language:** TypeScript everywhere.
- **API:** Node 20, Fastify, Zod for validation, OpenAPI generated from Zod schemas.
- **DB:** PostgreSQL 16, Prisma migrations. PostGIS optional; v1 uses lat/lng and haversine.
- **Queue and timers:** Redis 7 with BullMQ (SLA timers, notifications, reminders, auto-close).
- **Web consoles:** React 18, Vite, TanStack Query, React Router, Tailwind. One app, role-based routes.
- **Engineer app:** same React codebase built as a PWA. Service worker, IndexedDB (Dexie) for offline queue.
- **Realtime:** Server-Sent Events for console updates (`/events/stream`).
- **AI agent:** Anthropic API (model id from env `AI_MODEL`, default `claude-sonnet-5-5`), tool use against the internal service API. No model text ever writes to the DB directly.
- **Messaging:** WhatsApp Business Cloud API, SMS fallback. Behind a `Notifier` interface with a console/mock adapter for dev.
- **Auth:** OIDC JWT (env configured); roles in a `roles` claim. Customer links use signed short-lived tokens.
- **Testing:** Vitest (unit), Supertest (API), Playwright (UI), all in CI.

```
apps/service-call/
  api/                 Fastify service
    src/modules/       customers, units, tickets, dispatch, visits, inventory, notify, ai, analytics
    src/domain/        state machine, sla, warranty, priority, ranking (pure functions)
    src/jobs/          BullMQ workers
    prisma/schema.prisma
  web/                 React consoles + engineer PWA + customer pages
    src/features/{intake,ai,dispatch,closure,supervisor,engineer,customer}
  packages/shared/     Zod schemas, enums, types shared by api and web
  seed/                sample data (from the prototype)
  docs/
```

Keep `domain/` free of I/O so every business rule is unit-testable.

## 3. Enumerations (single source in `packages/shared`)

```ts
export const Channel = ['VOICE','WHATSAPP','APP','WEB','WALKIN','AI_VOICE','AI_WHATSAPP'] as const;
export const Priority = ['CRITICAL','HIGH','MEDIUM','LOW'] as const;
export const Region = ['NORTH','SOUTH','EAST','WEST'] as const;
export const ProductLine = ['CHIMNEY','WATER_PURIFIER','KITCHEN_AUTOMATION'] as const;
export const WarrantyType = ['IN_WARRANTY','OUT_OF_WARRANTY','AMC','GOODWILL'] as const;
export const Status = [
  'NEW','VALIDATED','TRIAGED','ASSIGNED','SCHEDULED','EN_ROUTE',
  'IN_PROGRESS','PARTS_PENDING','RESOLVED','CLOSED','REOPENED','CANCELLED'
] as const;
export const SlaState = ['GREEN','AMBER','RED','BREACHED'] as const;
```

## 4. Data model (Prisma schema, complete)

```prisma
model Customer {
  id        String   @id @default(cuid())
  name      String
  phone     String   @unique          // E.164
  language  String   @default("en")
  addresses Address[]
  units     ProductUnit[]
  tickets   Ticket[]
  createdAt DateTime @default(now())
}
model Address { id String @id @default(cuid()) customerId String customer Customer @relation(fields:[customerId],references:[id])
  line1 String city String pin String lat Float? lng Float? isDefault Boolean @default(false) }

model ProductUnit {
  serial       String   @id           // CHN-YYYY-XXXXX | RO-YYYY-XXXXX | KIT-YYYY-XXXXX
  line         ProductLine
  model        String
  customerId   String?
  customer     Customer? @relation(fields:[customerId],references:[id])
  purchaseDate DateTime
  warrantyMonths Int                  // product-level
  partWarranty Json                   // { "Motor Assembly": 60, ... } months per part
  amcUntil     DateTime?
  tickets      Ticket[]
}

model PinCoverage { pin String @id region Region franchisee String serviceLines ProductLine[] active Boolean @default(true) }

model Engineer {
  id String @id @default(cuid()) name String phone String region Region
  pins String[] skills ProductLine[] shiftStart String shiftEnd String
  lat Float? lng Float? active Boolean @default(true)
  vanStock VanStock[] visits Visit[]
}
model VanStock { engineerId String sku String qty Int  @@id([engineerId, sku]) engineer Engineer @relation(fields:[engineerId],references:[id]) }

model Ticket {
  id            String   @id                 // TKT-##### from sequence
  customerId    String
  serial        String?
  addressId     String?
  channel       Channel
  line          ProductLine
  part          String?
  faultCategory String?
  issueText     String
  priority      Priority
  status        Status   @default(NEW)
  warranty      WarrantyType?
  region        Region?
  slaMinutes    Int?
  slaDueAt      DateTime?
  slaPausedMs   Int      @default(0)
  slaPausedAt   DateTime?
  engineerId    String?
  reopenedFrom  String?
  isRepeat      Boolean  @default(false)
  aiSessionId   String?
  createdBy     String                        // user id or 'AI'
  createdAt     DateTime @default(now())
  updatedAt     DateTime @updatedAt
  closedAt      DateTime?
  visits        Visit[]
  events        TicketEvent[]
  feedback      Feedback?
}

model TicketEvent { id String @id @default(cuid()) ticketId String at DateTime @default(now())
  actor String actorType String  // USER | AI | SYSTEM | CUSTOMER
  type String from Status? to Status? data Json? }   // immutable audit log

model Visit {
  id String @id @default(cuid()) ticketId String engineerId String
  slotStart DateTime slotEnd DateTime
  tripStartedAt DateTime? arrivedAt DateTime? arrivedLat Float? arrivedLng Float?
  completedAt DateTime? outcome String?         // RESOLVED | PARTS_PENDING | CUSTOMER_ABSENT | NOT_FIXED
  rootCause String? checklist Json? notes String?
  otpVerified Boolean @default(false) signatureUrl String?
  photos Photo[] parts PartUsage[]
}
model Photo { id String @id @default(cuid()) visitId String kind String url String takenAt DateTime } // BEFORE | AFTER
model PartUsage { id String @id @default(cuid()) visitId String sku String qty Int source String } // VAN | REGION

model Part { sku String @id name String line ProductLine consumable Boolean @default(false) }
model Stock { sku String region Region qty Int reorderLevel Int  @@id([sku, region]) }
model Reservation { id String @id @default(cuid()) ticketId String sku String qty Int source String status String } // HELD | USED | RELEASED
model ReorderDraft { id String @id @default(cuid()) sku String region Region predictedQty Int status String } // DRAFT | APPROVED | REJECTED

model Feedback { ticketId String @id rating Int fixed Boolean comment String? at DateTime @default(now()) ticket Ticket @relation(fields:[ticketId],references:[id]) }
model QaReview { id String @id @default(cuid()) ticketId String reason String result String? reviewer String? at DateTime? }
model OtpChallenge { ticketId String @id codeHash String expiresAt DateTime attempts Int @default(0) }
model AiSession { id String @id @default(cuid()) channel Channel transcript Json extracted Json confidence Json outcome String handoffReason String? createdAt DateTime @default(now()) }
model Config { key String @id value Json }   // SLA table, thresholds, price list refs
```

Seed from the prototype: 10 tickets, 8 inventory rows, products Chimney / Water Purifier (RO) / Kitchen Automation with their parts, engineers Ajay Kumar (North), Sneha Patil (West), Karthik R (South) plus East engineers (Rahul, Imran, Divya) marked sample.

## 5. State machine (implement in `domain/stateMachine.ts`)

Transitions are a table; anything not listed is rejected with `409 INVALID_TRANSITION`. Every transition writes a `TicketEvent`, then emits a domain event.

| From | To | Guard | Side effects |
|---|---|---|---|
| NEW | VALIDATED | serial valid or explicitly none; PIN covered | stamp `warranty`, `region`; start response clock |
| NEW | CANCELLED | reason given | |
| VALIDATED | TRIAGED | `line`, `issueText`, `priority` set | set `slaMinutes`, `slaDueAt` |
| VALIDATED | CANCELLED | reason | |
| TRIAGED | ASSIGNED | engineer active, in region or override | reserve part; SLA timer jobs |
| TRIAGED | RESOLVED | remote fix accepted by customer | CSAT job |
| ASSIGNED | SCHEDULED | slot chosen | notify customer with ETA; reminder job T-1h |
| SCHEDULED | EN_ROUTE | actor is assigned engineer | live ETA notify |
| SCHEDULED | ASSIGNED | customer or dispatcher reschedule | pause SLA if customer-driven |
| EN_ROUTE | IN_PROGRESS | check-in within 300 m of address (configurable), else needs `geoOverrideReason` | open checklist |
| IN_PROGRESS | PARTS_PENDING | part not on van | pause SLA, create transfer request |
| PARTS_PENDING | SCHEDULED | part arrived, new slot | resume SLA |
| IN_PROGRESS | RESOLVED | closure gates pass (section 8) | post part usage, CSAT job, invoice if paid |
| RESOLVED | CLOSED | QA pass, or 48 h with no complaint and not sampled | lock ticket, book KPIs |
| RESOLVED | REOPENED | customer says not fixed, within 7 days | flag; link `reopenedFrom` |
| CLOSED | REOPENED | within 7 days | same |
| REOPENED | ASSIGNED | dispatcher or auto | offer previous engineer first |

Reject writes to a CLOSED ticket except reopen. Repeat flag: same `serial` and `faultCategory` within 30 days of a previous RESOLVED ticket sets `isRepeat`.

## 6. Business rules (pure functions in `domain/`)

### 6.1 Serial and warranty (`warranty.ts`)
1. Regex: `^(CHN|RO|KIT)-\d{4}-\d{5}$`. Prefix maps to product line. Invalid format returns `422 SERIAL_FORMAT`.
2. Lookup `ProductUnit`. Not found returns `404 SERIAL_NOT_FOUND`.
3. If `amcUntil >= today`: `AMC`. Else if `purchaseDate + warrantyMonths >= today` (or the affected part's `partWarranty` months): `IN_WARRANTY`. Else `OUT_OF_WARRANTY`.
4. Part-level: warranty is evaluated for the affected part. Consumable parts (`Part.consumable`) are never covered: warranty result carries `consumableNotCovered: true`.
5. Only `role=manager` may set `GOODWILL` (override), with a reason recorded in the event.
Known warranty periods from the prototype: Water Purifier 12 months, Chimney 60 months (Motor), Kitchen Automation 24 months.

### 6.2 PIN coverage
`GET /pins/{pin}/coverage` returns `{covered, region, franchisee}`. Uncovered PIN blocks VALIDATED unless a manager overrides.

### 6.3 Priority (`priority.ts`)
Rules in order, first match wins. Keep keyword lists in `Config`.
1. Safety keywords (smoke, burning smell, shock, sparks, leak near power) or product line WATER_PURIFIER with "no water output": `CRITICAL` (water-failure rule is `PROPOSED`).
2. Product unusable (no power, no output, not working, motor failure): `HIGH`.
3. Degraded function (low suction, slow, noise, flickering): `MEDIUM`.
4. Cosmetic, advice, filter cleaning: `LOW`.
Agents and AI may propose; dispatcher or manager may change with an event.

### 6.4 SLA (`sla.ts`)
Resolution target minutes, from `Config.sla` (`PROPOSED`, High 180 and Low 360 are from the prototype):

| Priority | Minutes |
|---|---|
| CRITICAL | 90 |
| HIGH | 180 |
| MEDIUM | 240 |
| LOW | 360 |

`elapsed = now - createdAt - slaPausedMs`. `pct = elapsed / slaMinutes`.
State: `<0.5 GREEN`, `<0.8 AMBER`, `<1.0 RED`, `>=1.0 BREACHED`.
Pause when status becomes PARTS_PENDING or a customer-requested reschedule; resume otherwise. Log pause and resume as events.
BullMQ delayed jobs at 50%, 80%, 100% of remaining time, recomputed on every pause, resume or priority change:
- 50%: notify dispatcher; if still unassigned, run auto-assign.
- 80%: notify region manager; suggest reassignment; send customer a delay message.
- 100%: mark BREACHED; notify service head; add to review queue.

### 6.5 Dispatch ranking (`ranking.ts`)
Candidates: active engineers on shift, `pins` includes ticket PIN or same region. Score 0 to 100:

```
score = 30*skill + 25*distanceScore + 15*loadScore + 20*partOnVan + 10*ftfRate
skill         = 1 if line in skills, 0.5 if partial, 0 otherwise (0 excludes)
distanceScore = max(0, 1 - km/30)
loadScore     = max(0, 1 - openJobsToday/6)
partOnVan     = 1 if van stock >= needed qty else 0
ftfRate       = engineer 90-day first-time-fix, 0..1
```
Weights are `Config.dispatchWeights` (`PROPOSED`). Return the breakdown per component so the UI can show it (board 6). Different region engineers are allowed only with a dispatcher override.
Auto-dispatch: on for MEDIUM and LOW when the top score >= 70 and the part is available; otherwise it stays in the dispatcher queue.

### 6.6 Inventory
On ASSIGNED create a `Reservation` from van first, then region stock. On RESOLVED convert to used and decrement. Release on cancel or reassign.
Reorder prediction (v1): `predicted = ceil(avgWeeklyUse(4 weeks) * 2) - stock` when `stock < reorderLevel`; create `ReorderDraft`. Status labels: OK, Low (below reorder), Critical (below 40% of reorder level, `PROPOSED`).

### 6.7 Closure gates (`closure.ts`)
`RESOLVED` requires all:
1. Checklist complete (before and after items).
2. BEFORE photo and AFTER photo present.
3. Root cause chosen from the fault list.
4. Parts used logged and scanned (or explicit "none").
5. Customer OTP verified, or signature captured (then a `QaReview` with reason `NO_OTP` is created).
6. Payment recorded if `warranty != IN_WARRANTY` or a chargeable consumable was used.
Return the list of failed gates so the UI can show which one blocks.

### 6.8 Post-resolution
- OTP: 4 digits, hashed, 15 min expiry, 5 attempts, sent via WhatsApp and SMS when the visit starts and re-sendable.
- QA sample: all CRITICAL, all `NO_OTP`, first 5 jobs of any new engineer, plus 10% random (`Config.qaSampleRate`).
- Auto-close job at `resolvedAt + 48h` if no complaint and QA is not pending.
- Reopen window 7 days. Reopen requests from feedback "not fixed" are automatic.

## 7. API contract

Base `/v1`. JSON. All writes accept `Idempotency-Key`. Errors: `{code, message, details}`.

| Method | Path | Purpose | Roles |
|---|---|---|---|
| POST | `/calls` | Register call: finds or creates customer by phone, creates ticket NEW | agent, ai |
| GET | `/customers/lookup?phone=` | Caller ID match with products and recent tickets | agent, ai |
| GET | `/units/{serial}/warranty?part=` | Warranty result | agent, ai, dispatcher |
| GET | `/pins/{pin}/coverage` | PIN coverage | agent, ai |
| GET | `/tickets?status=&region=&sla=&engineerId=&q=` | List with SLA state | all staff |
| GET | `/tickets/{id}` | Detail with events | staff |
| POST | `/tickets/{id}/validate` | NEW to VALIDATED | agent, ai, system |
| POST | `/tickets/{id}/triage` | Set fault, priority; to TRIAGED | agent, ai, dispatcher |
| GET | `/tickets/{id}/dispatch-options` | Ranked engineers with score breakdown, part availability, slots | dispatcher |
| POST | `/tickets/{id}/dispatch` | Assign engineer, reserve part | dispatcher, system |
| POST | `/tickets/{id}/schedule` | Choose slot | dispatcher, customer |
| POST | `/visits/{id}/trip-start` | EN_ROUTE | engineer |
| POST | `/visits/{id}/checkin` | IN_PROGRESS with geo | engineer |
| PUT | `/visits/{id}/work` | Checklist, root cause, parts (idempotent, offline-safe) | engineer |
| POST | `/visits/{id}/photos` | Upload photo, kind BEFORE or AFTER | engineer |
| POST | `/tickets/{id}/otp/send` | Send OTP | engineer, system |
| POST | `/tickets/{id}/otp/verify` | Verify OTP | engineer |
| POST | `/tickets/{id}/resolve` | Runs closure gates; RESOLVED or `422 GATES_FAILED` with list | engineer |
| POST | `/tickets/{id}/close` | QA close | qa, system |
| POST | `/tickets/{id}/reopen` | Reopen with reason | agent, dispatcher, qa, customer |
| POST | `/tickets/{id}/cancel` | Cancel with reason | agent, dispatcher |
| GET | `/engineer/me/jobs` | Today's jobs | engineer |
| POST | `/sync` | Batch of offline mutations, returns per-item result | engineer |
| GET | `/customer/t/{token}` | Customer tracking view | customer token |
| POST | `/customer/t/{token}/feedback` | Rating, fixed, comment | customer token |
| GET | `/inventory` | Stock, status, predicted demand | dispatcher, manager |
| POST | `/reorders/{id}/approve` | Approve reorder draft | manager |
| GET | `/analytics/summary` | KPIs, region FTF, watchlist, review queue | manager |
| GET | `/events/stream` | SSE of ticket and SLA events | staff |
| POST | `/ai/sessions/{id}/turn` | Customer message in, AI reply out | webhook |
| POST | `/webhooks/whatsapp` | Inbound WhatsApp, delivery receipts | provider |

Domain events published on every status change: `ticket.created`, `ticket.validated`, `ticket.triaged`, `ticket.assigned`, `ticket.scheduled`, `visit.started`, `visit.arrived`, `ticket.parts_pending`, `ticket.resolved`, `ticket.closed`, `ticket.reopened`, `sla.amber`, `sla.red`, `sla.breached`, `stock.low`.

## 8. AI agent (module `ai/`)

Maps to design board 5.

**Flow:** greet in the customer's language, identify customer (phone), ask problem, get serial (text or photo OCR), confirm PIN, check warranty, propose slot, confirm, create ticket.

**Implementation:** an agent loop over the Anthropic Messages API with tool use. Tools are thin wrappers over the internal service API and run with the `ai` role:
`lookup_customer`, `validate_serial`, `check_pin`, `check_duplicate`, `classify_issue`, `propose_slots`, `create_ticket`, `handoff_to_human`.

**Structured extraction:** after each turn, the model returns JSON matching:
```ts
{ fields: { customerName, phone, line, serial, pin, part, symptom, priority, warranty, slot },
  confidence: { <field>: number 0..1 }, intent: 'NEW_ISSUE'|'STATUS'|'CANCEL'|'OTHER' }
```

**Decision policy (config-driven):**
- Create ticket automatically only if all required fields (customer, line, serial or explicit none, pin, symptom, priority, slot) have confidence >= 0.85 and no handoff rule fires.
- One re-ask for a field between 0.60 and 0.85.
- Handoff to a human immediately on: anger or request for a person, safety words (smoke, burning, shock, leak), serial not found twice, warranty dispute or refund request, two failed attempts on any field.
- Handoff creates a draft ticket with the transcript summary and extracted fields attached so the agent continues without re-asking.

**Guardrails:** the AI never states warranty or price except from tool results; never promises free repair; every tool call is written as a `TicketEvent` with `actorType=AI`; the transcript and confidences are stored in `AiSession`; a weekly QA sample of 20 AI tickets is exposed in the QA queue.

**Testing:** a scripted-conversation harness with a fake model so tests do not call the network; plus an eval set of 30 transcripts (include the Priya Iyer example from the design) with expected extracted fields.

## 9. Notifications (module `notify/`)

Interface `Notifier.send(channel, to, templateId, vars)`. Templates (English, add Hindi later), variables in `{}`:

| Template | Trigger | Text |
|---|---|---|
| `ticket_created` | NEW | Hello {name}, your ticket {ticketId} is registered. |
| `assigned` | SCHEDULED | Hello {name}, engineer {engineer} will visit {slot} for ticket {ticketId}. Track: {link} |
| `reminder` | T-1h | Your engineer arrives between {slot}. |
| `en_route` | EN_ROUTE | {engineer} is on the way, about {eta} minutes. |
| `otp` | trip start | Your code is {code}. Share it only when the repair is done. |
| `delay` | SLA 80% | Sorry for the delay on {ticketId}. We are prioritising it. |
| `csat` | RESOLVED | How did the visit go? {link} |

WhatsApp first, SMS fallback after delivery failure or 2 minutes without a receipt. Rate limit and de-duplicate per ticket and template.

## 10. Offline-first engineer app

Maps to boards 8a to 8d.
- All engineer screens read from a local IndexedDB cache of today's jobs, ticket details, checklist, and van stock. Sync on app open, every 60 s when online, and on `online` event.
- Every write is a mutation `{id (uuid), type, visitId, payload, clientTime}` appended to a local queue and applied optimistically.
- `POST /sync` receives the batch; server applies in order, idempotent by mutation id, returns `{applied, rejected[{id, reason}]}`. Conflicts: server state machine wins; the UI shows the rejection on the job.
- Photos are queued as blobs, uploaded in the background, and gate closure until uploaded (or marked pending with a warning that blocks RESOLVED sync until done).
- Header chip shows "Offline, N to sync".
- High contrast, 44 px touch targets, no hover-only actions.
- Geo check-in uses the device location; store lat/lng and accuracy.

## 11. Screen-to-code map

| Design board | Route | Key components and data |
|---|---|---|
| 1 Lifecycle | `docs/` only | Reference diagram |
| 2 Status and SLA | `docs/` and reused in `SlaBadge`, `StatusChip` | Colours: green under 50%, amber to 80%, red after |
| 3 Data, APIs, roles | Sections 4, 7, 12 of this document | |
| 4 Intake console | `/intake` | `CallerPanel`, `ProductPicker`, `WarrantyBanner`, `PinBanner`, `IssueForm`, `CopilotPanel`, `TicketPreview`; calls `/customers/lookup`, `/units/.../warranty`, `/pins/.../coverage`, `/calls`, triage, dispatch |
| 5 AI agent | `/ai/sessions` (monitor) and webhook | `ConversationView`, `ExtractedFields` with confidence bars, `HandoffRules` |
| 6 Dispatch | `/dispatch` | `QueueList`, `EngineerRankingTable` (uses `dispatch-options`), `PartReservationCard`, `SlotPicker`, `MessagePreview` |
| 7 Closure and QA | `/qa`, gate list inside engineer close screen | `ClosureGates`, `OtpPanel`, `PostResolveTimeline`, `ReviewQueue` |
| 8a Jobs | `/e/jobs` | Job cards with SLA bar, chips (warranty, part on van), offline chip |
| 8b Job detail | `/e/jobs/:id` | Call, navigate, service-history hint, parts reserved, progress chips |
| 8c Work | `/e/jobs/:id/work` | Checklist, root cause, parts scan, draft save |
| 8d Close | `/e/jobs/:id/close` | Photos, summary, OTP input, signature pad, Resolve |
| 9 Supervisor | `/manager` | KPI tiles, FTF by region vs target, watchlist, parts at risk, suggested action |
| 10a Track | `/t/:token` | Status timeline, engineer card |
| 10b Feedback | `/t/:token/feedback` | Rating, fixed yes/no |

**Visual spec (from the design):** font IBM Plex Sans and IBM Plex Mono for IDs. Tokens: bg `#f5f3ee`, panel `#fffefb`, line `#ddd8cb`, ink `#1d2126`, muted `#5b6068`, teal `#0e6b5e` (primary, register/validate/triage), blue `#1f4fbf` (dispatch/confirm), amber `#9a5b00` (on-site), plum `#6b3a8c` (verify/close), red `#b3261e` (risk/reopen). Chip tints: teal `#dcefe9`, blue `#e2e9fa`, amber `#fbeed2`, red `#f9dfdc`, plum `#eddff5`, neutral `#ebe8df`. Put these in `web/src/styles/tokens.css` and Tailwind config. Use real `<button>`, `<a>`, `<label>` elements. Text contrast at least 4.5:1.

## 12. Roles and permissions (enforce in API middleware, test each cell)

| Action | Agent | AI | Dispatcher | Engineer | QA | Manager |
|---|---|---|---|---|---|---|
| Register call | Y | Y | Y | | | Read |
| Edit triage | Y | Y | Y | | | Y |
| Assign engineer | | | Y | | | Y |
| Update visit | | | Read | Own jobs | Read | Read |
| Resolve job | | | | Own jobs | Y | |
| Reopen | Y | | Y | | Y | Y |
| Override warranty or PIN | | | | | | Y |
| Approve reorder | | | | | | Y |

Engineers only ever see tickets assigned to them. Customer tokens see only their ticket and never internal notes.

## 13. Non-functional requirements
- p95 API latency under 300 ms for list and detail at 50 k tickets.
- Every state change and AI action is in `TicketEvent`; events are append-only.
- No PII in logs; phone numbers masked.
- Photos in object storage (S3 compatible), signed URLs, 10 MB limit, EXIF stripped.
- Config in env and `Config` table; secrets never committed.
- OpenTelemetry traces on API and jobs; a `/healthz` and `/readyz`.
- Accessibility: keyboard reachable, labelled controls, 44 px targets on mobile.

## 14. Build plan (phases, prompts, acceptance)

Give Claude Code one phase at a time. Each prompt below is self-contained.

### Phase 0: Scaffold
Prompt: "Create the monorepo in `apps/service-call/` per section 2 of `docs/service-call-system/CLAUDE_CODE_SPEC.md`. Set up pnpm workspaces, TypeScript strict, Fastify API skeleton with `/healthz`, Prisma with the section 4 schema, `docker-compose` for Postgres and Redis, Vitest, ESLint, and CI. Implement the enums package."
Accept: `pnpm test` and `pnpm build` pass; `docker compose up` gives a healthy API; migration applies.

### Phase 1: Domain core (no HTTP)
Prompt: "Implement `domain/` per sections 5 and 6: state machine, warranty, priority, sla, ranking, closure gates. Pure functions, 100% branch coverage on state machine, warranty, sla."
Accept tests (must exist):
- Every transition in the section 5 table is allowed; every other pair is rejected.
- Serial `CHN-2023-88741` valid; `CH-2023-88741` rejected with `SERIAL_FORMAT`.
- SLA: High ticket with 179 min elapsed is RED; 180 is BREACHED; pause of 60 min shifts the state back.
- Ranking: an engineer with the part on van outranks a closer one without it (matches the Control PCB example in board 6).
- Closure gates return the exact failed list (after photo missing, OTP missing).

### Phase 2: API and persistence
Prompt: "Implement the section 7 endpoints for customers, units, pins, tickets, triage, dispatch, schedule, visits, otp, resolve, close, reopen, cancel. Wire the domain module, write TicketEvents, enforce section 12 permissions, idempotency keys, OpenAPI output."
Accept: Supertest suite runs the full happy path NEW to CLOSED; invalid transitions return 409; permission matrix test covers all cells; duplicate POST with the same Idempotency-Key does not duplicate.

### Phase 3: Timers, inventory, notifications
Prompt: "Add BullMQ jobs for SLA thresholds, reminders, OTP expiry, auto-close, and reorder prediction. Add reservations and stock updates. Add the `Notifier` interface with a mock adapter and the section 9 templates."
Accept: with a fake clock, a HIGH ticket triggers notifications at 90, 144 and 180 minutes; PARTS_PENDING pauses and resumes; auto-close fires at 48 h; stock below reorder creates one draft only.

### Phase 4: Web consoles
Prompt: "Build the React app with tokens from section 11. Implement Intake (board 4), Dispatch (6), QA/Closure (7), Supervisor (9), using the section 7 API and SSE. Match the design layouts."
Accept: Playwright: an agent registers a call for serial `CHN-2023-88741` and PIN `110001` and sees the in-warranty and PIN-covered banners; dispatcher assigns the top-ranked engineer; supervisor tiles update through SSE.

### Phase 5: Engineer PWA (offline)
Prompt: "Build the engineer routes (boards 8a to 8d) as a PWA with IndexedDB queue and `/sync` per section 10."
Accept: Playwright with network offline: complete checklist, add part, take photos, then reconnect; server ends in RESOLVED only after gates pass; a rejected mutation is shown on the job.

### Phase 6: Customer pages
Prompt: "Build `/t/:token` and feedback pages (10a, 10b) with signed tokens. Feedback 'not fixed' auto-reopens."
Accept: token for another ticket returns 403; feedback No creates REOPENED and a dispatch queue item.

### Phase 7: AI agent
Prompt: "Implement the `ai/` module per section 8 with tool use, the confidence policy, handoff rules, WhatsApp webhook, and the monitor screen (board 5). Use a fake model in tests."
Accept: scripted Priya Iyer conversation creates TKT with all fields >= 0.85; scripted 'I smell burning' conversation hands off with a draft ticket; two failed serial attempts hand off.

### Phase 8: Analytics and hardening
Prompt: "Implement `/analytics/summary` (first-time fix, SLA breach, time to assign, repeat visits, CSAT, stock-outs), repeat-visit detection, QA sampling, seed data, load test at 50 k tickets, and observability."
Accept: KPIs match a hand-computed fixture; repeat flag appears for the second visit within 30 days on the same serial and fault.

## 15. Definition of done
- All phases accepted, CI green, OpenAPI published.
- Every screen in section 11 implemented and visually checked against the design canvas.
- README with run instructions and env variables; `.env.example`.
- Runbook: SLA timer recovery after a Redis restart (rebuild jobs from ticket rows).

## 16. Open questions for the business (defaults are in `Config`)
1. Final SLA targets for CRITICAL and MEDIUM.
2. Warranty policy for consumables and part-level periods for Water Purifier and Kitchen Automation.
3. Price list and payment provider.
4. Languages beyond English and Hindi for the AI agent.
5. Distance and geofence limits, engineer shift rules.
6. QA sample rate and who staffs the QA queue.
7. Whether cross-region dispatch needs manager approval.
