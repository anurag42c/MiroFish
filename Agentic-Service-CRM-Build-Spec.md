# Agentic Service Management CRM: Build Spec for Claude Code

Stack: Python + OpenClaw agent runtime, WhatsApp through a BSP (Gupshup, Twilio or AiSensy), PostgreSQL, React console.
Scope: call registration, allocation to franchisee or company engineer, field execution, spare parts, closure through WhatsApp, and monitoring at every level.
Status: draft v0.1. Items marked **[ASSUMPTION]** are defaults to confirm with the client. Numeric thresholds are configurable defaults, not facts.

---

## 0. How to use this file

### 0.1 Kickoff prompt (paste this into Claude Code)

```
You are building an agentic Service Management CRM. The full specification is in
Agentic-Service-CRM-Build-Spec.md in this repo. Read it end to end before doing anything.

Rules for this build:
1. Do not write application code in your first response. First produce:
   (a) CLAUDE.md summarising the rules in section 0.2 and the repo layout in section 3.4,
   (b) a phase plan that matches section 14, with risks and open questions,
   (c) the list of assumptions from section 16 that block Phase 0.
2. Build strictly phase by phase (section 14). Do not start a phase until the previous
   phase's acceptance tests pass. Show me the test output.
3. The deterministic core (state machine, stock ledger, SLA timers, policy engine) is the
   source of truth. Agents propose; the core disposes. Never let an agent write to the
   database directly.
4. Before integrating OpenClaw, read its current docs at https://docs.openclaw.ai (gateway,
   agents, skills, sandboxing, tool allow/deny) and write down what you verified in
   docs/openclaw-notes.md. Do not assume API shapes from this spec.
5. Use the mock WhatsApp BSP adapter and the WhatsApp simulator (section 15) for all local
   development and tests. No real BSP credentials in the repo.
6. Ask me before any decision that changes the data model in section 5 or the autonomy
   tiers in section 9.
```

### 0.2 Non-negotiable engineering rules (copy into CLAUDE.md)

- Python 3.12, type hints everywhere, `ruff` and `mypy --strict` clean, `pytest` with coverage gate on the core packages.
- Pydantic v2 models at every boundary: API, events, agent inputs and outputs, tool arguments.
- Every state change is an event first. State is derived from the event log plus a projection table.
- Every side effect is idempotent and carries an `idempotency_key`. Webhooks are safe to replay.
- Tenant and actor identity come from the request or run context, never from a model-generated argument.
- No PII in logs. Phone numbers are masked in logs and traces (`+91******1234`).
- All LLM and agent calls go through the `AgentRuntime` port (section 4). No direct SDK calls in domain code.
- Every agent output is schema-validated. Invalid output gets one retry, then falls back to a rule or a human queue.
- Migrations with Alembic. No manual schema edits.
- Every agent has an evaluation dataset and a pass threshold before it is allowed above autonomy tier A1 (section 9).

---

## 1. Product brief

### 1.1 Problem

A manufacturer of appliances or equipment sells through dealers and services through a mix of company-owned engineers and franchisee service partners. Service calls arrive by phone, WhatsApp, app, web and dealers. Today calls are registered by hand, allocated by phone, closed on paper or by voice, and spare parts are tracked in spreadsheets. The result is late visits, wrong parts, repeat calls, disputed franchisee claims and no live view of who is stuck.

### 1.2 Goal

One system that takes a call from first contact to verified closure with the least human effort, where:

1. **Registration** is conversational and complete: the right customer, product, warranty status and fault, in one pass.
2. **Allocation** picks the right owner (franchisee or company engineer) and the right engineer, and reallocates when the plan breaks.
3. **Execution and closure** happen on the engineer's WhatsApp: accept, travel, arrive, request spares, diagnose, close with proof.
4. **Spares** are reserved, issued, consumed and returned against a ledger, with forecasting and replenishment.
5. **Monitoring** runs at every level (engineer, franchisee, branch, region, national) and escalates before an SLA breaks, not after.

### 1.3 Users

| User | Main surface | Main jobs |
|---|---|---|
| Customer | WhatsApp, phone, app, web | Register a call, get status, approve visit, confirm closure with OTP, rate |
| Call-centre agent | Web console | Register calls the bot could not finish, handle exceptions |
| Company service engineer | WhatsApp | Accept jobs, navigate, request parts, close calls |
| Franchisee owner or dispatcher | Web portal and WhatsApp | Accept or reject calls, assign engineers, manage stock, see claims |
| Franchisee engineer | WhatsApp | Same as company engineer |
| Branch or regional service manager | Web console and WhatsApp digest | Monitor SLA, backlog, stock, quality; resolve escalations |
| Spares planner | Web console | Stock levels, replenishment, defective returns |
| Admin | Web console | Masters, policies, SLA, escalation matrix, agent settings |

### 1.4 Non-goals for v1

- Full ERP, GST invoicing and general ledger. Integrate through an adapter (section 11.3).
- Route optimisation across a full day for hundreds of engineers. v1 scores and sequences; it does not solve a routing problem.
- Voice bot for inbound phone calls. v1 takes call-centre transcripts or agent-entered calls; IVR can come later.
- Customer-facing mobile app.

---

## 2. Design principles

1. **Agents propose, the core disposes.** An agent can recommend an allocation, a part list or a closure decision. The deterministic core checks policy, stock and state rules and then commits. An agent never mutates state directly.
2. **Autonomy is earned and per-action.** Each action type has an autonomy tier (section 9). A new agent starts at "suggest only".
3. **WhatsApp is the engineer's operating system.** Engineers should never need a second app for a normal job. Every step has a button, a list or a short reply.
4. **Evidence over assertion.** Closure requires proof: OTP, serial number photo, geo-presence, parts reconciliation. The closure agent checks the proof; it does not trust the claim.
5. **Silence is a signal.** The monitoring agent watches for things that did not happen: no acceptance, no arrival, no update, no part movement.
6. **Every level sees its own slice.** The same data feeds engineer, franchisee, branch, region and national views with the right scope and the right KPIs at each.
7. **Explain every decision.** Each allocation, escalation and closure decision stores a short reason a supervisor can read.
8. **India first.** Pincode-based serviceability, Indian phone formats, Hindi and regional languages in voice notes, DPDP Act consent, data hosted in India.

---

## 3. Architecture

### 3.1 Planes

```
                        +--------------------------------------------+
   Customers, dealers,  |  CHANNEL BRIDGE (data plane, no LLM)       |
   engineers, franchise |  WhatsApp BSP webhooks, phone/CC intake,   |
   staff  <-----------> |  web forms, media store, outbound queue    |
                        +---------------------+----------------------+
                                              |  normalised events
                        +---------------------v----------------------+
                        |  CORE SERVICE (deterministic, source of    |
                        |  truth)                                    |
                        |  call state machine | SLA timers |         |
                        |  allocation engine | stock ledger |        |
                        |  policy engine | approvals | audit          |
                        +----+----------------------+-------------+--+
                             | tool calls           | events      | reads
                        +----v----------+    +------v------+  +---v---------+
                        | AGENT PLANE   |    | WORKFLOW    |  | ANALYTICS   |
                        | OpenClaw      |    | Celery/     |  | projections,|
                        | gateway +     |    | Temporal    |  | KPIs,       |
                        | agents/skills |    | timers      |  | dashboards  |
                        +---------------+    +-------------+  +-------------+
```

- **Channel Bridge** never calls a model. It verifies webhooks, deduplicates, stores media, maps a sender to an actor, and emits normalised events.
- **Core Service** owns all business state and rules. It exposes an internal API used by the console and by the agent tools.
- **Agent Plane** runs OpenClaw. Agents read context through read tools and propose actions through proposal tools. The core validates proposals.
- **Workflow and timers** run SLA clocks, acceptance timeouts, reminders and escalation ladders. Timers are durable.
- **Analytics** builds projections for dashboards and for the monitoring agent.

### 3.2 Technology choices

| Concern | Choice | Notes |
|---|---|---|
| API | FastAPI, Pydantic v2, SQLAlchemy 2, Alembic | Async where it helps, sync where it is simpler |
| Database | PostgreSQL 16, PostGIS, pgvector | PostGIS for geo-fence and nearest engineer; pgvector for similar-fault retrieval |
| Queue and timers | Celery + Redis to start; keep a `TimerService` interface so Temporal can replace it | Durable timers matter more than the engine |
| Object storage | S3-compatible, India region | Photos, voice notes, PDFs. Presigned URLs only |
| Agent runtime | OpenClaw gateway, behind an `AgentRuntime` port | See section 4 |
| WhatsApp | BSP behind a `WhatsAppProvider` port | See section 10 |
| Speech to text | Provider port with Hindi and English support **[ASSUMPTION]** | Voice notes from engineers and customers |
| OCR | Provider port for serial plates and part labels | Fallback: manual entry |
| Frontend | React, TypeScript, Vite, Tailwind, TanStack Query, MapLibre | Web console and franchisee portal |
| Auth | OIDC for staff; phone-bound identity for WhatsApp actors | Row-level tenant isolation |
| Observability | OpenTelemetry, structured logs, Prometheus, Grafana; agent traces stored with `agent_run` rows | |
| Packaging | Docker Compose for local; Helm chart later | |

### 3.3 Multi-tenancy

Every table has `tenant_id`. Use Postgres row-level security keyed on a session variable. A tenant is a manufacturer (brand owner). Franchisees and engineers belong to a tenant through `service_partner`.

### 3.4 Repository layout

```
/
  CLAUDE.md
  Agentic-Service-CRM-Build-Spec.md
  docs/                      openclaw-notes.md, adr/, runbooks/
  apps/
    api/                     FastAPI app: routers, deps, auth
    worker/                  Celery workers, timers, scheduled jobs
    bridge/                  webhook receivers, outbound sender
    console/                 React web console and franchisee portal
  packages/
    core/                    state machine, SLA, allocation, ledger, policy
      calls/  allocation/  stock/  sla/  policy/  closure/
    domain/                  entities, pydantic schemas, events
    agents/                  agent definitions, prompts, evals
      runtime/               AgentRuntime port + OpenClaw adapter + stub adapter
      tools/                 tool server: read tools, proposal tools
      registration/  allocation/  dispatch/  closure/  spares/  monitor/  customer/
    channels/
      whatsapp/              WhatsAppProvider port, mock, gupshup, twilio, aisensy
      voice/  ocr/  storage/
    analytics/               projections, KPI definitions
  tests/
    unit/  integration/  e2e/  evals/
  tools/
    whatsapp_simulator/      chat UI + scripted scenarios
    seed/                    synthetic tenant, pincodes, engineers, parts, calls
  infra/                     docker-compose.yml, helm/
```

---

## 4. Agent runtime: OpenClaw

OpenClaw is an open-source agent framework built around a **Gateway** (control plane for sessions, tools, events and channels), per-agent workspaces, skills, multi-agent routing with bindings, and sandboxing with tool allow and deny lists. Read the current docs before you design against it; the details below are the intent, and you must verify each one.

### 4.1 Integration stance

1. **OpenClaw runs the agents. It does not own channels or business state.**
2. **Disable OpenClaw's own WhatsApp channel.** All WhatsApp traffic goes through the BSP into our Channel Bridge. OpenClaw never holds WhatsApp credentials or a personal-number session.
3. **Expose one narrow tool server** (MCP server or OpenClaw skill that calls our internal API). Nothing else. Deny shell execution, file write, browser and any tool that is not on the allow list.
4. **Sandbox every agent** (`sandbox.mode: all` or the current equivalent) with a per-agent allow list.
5. **Inject `tenant_id`, `actor`, `call_id` from run context.** They are never tool arguments the model can set.
6. **Run OpenClaw inside our network boundary** in an India region. No customer data goes to a third-party model endpoint that lacks India residency **[ASSUMPTION: confirm with client]**.
7. Wrap OpenClaw in an `AgentRuntime` port so tests run with a deterministic stub and so we can replace the runtime.

### 4.2 The port

```python
# packages/agents/runtime/port.py
class AgentTask(BaseModel):
    agent_id: str                 # e.g. "allocation"
    run_id: UUID
    tenant_id: UUID
    actor: ActorRef               # who triggered this
    call_id: UUID | None
    objective: str                # short natural-language goal
    context: dict[str, Any]       # already-fetched, PII-minimised facts
    output_schema: type[BaseModel]
    max_steps: int = 8
    deadline_s: int = 20

class AgentResult(BaseModel):
    run_id: UUID
    status: Literal["ok", "invalid_output", "timeout", "error"]
    output: BaseModel | None
    reasoning_summary: str        # short, human-readable, stored for audit
    tool_trace: list[ToolCallRecord]
    cost: CostRecord

class AgentRuntime(Protocol):
    async def run(self, task: AgentTask) -> AgentResult: ...
```

Adapters to build: `OpenClawRuntime` (talks to the gateway), `StubRuntime` (returns fixtures for tests), and optionally `RulesOnlyRuntime` (fallback that uses the deterministic scorer for allocation so the system keeps working if the runtime is down).

### 4.3 Agent catalogue

| Agent | Trigger | Reads | Proposes | Default tier |
|---|---|---|---|---|
| Registration | Inbound message or form | Customer, asset, warranty, open calls, KB | Create call, ask next question, link to open call | A2 for create; A1 for warranty override |
| Triage | Call registered | Fault text, product, history, KB | Fault code, priority, likely parts, skill needed | A2 |
| Allocation | Call ready to allocate, or reallocation trigger | Serviceability, engineers, partners, load, stock | Owner (franchisee or company), engineer, slot | A2 within policy, A1 outside |
| Dispatch | Call allocated | Engineer state, calendar | Sequence for the day, reminders, ETA messages | A2 |
| Field assistant | Engineer message | Job card, manuals, similar faults | Diagnostic steps, part suggestions, next step prompt | A2 |
| Spares | Part request, low stock, DOA | Ledger, locations, demand | Reserve, transfer, reorder, substitute | A1 for buy, A2 for reserve and transfer |
| Closure | Closure submitted | Job card, photos, OTP, geo, parts, history | Accept, reject with reasons, send to review | A2 for clean closures, A1 for flagged |
| Monitor | Every N minutes and on events | Projections, SLA clocks | Alerts, escalations, nudges, daily digests | A2 |
| Customer comms | Status change | Call, customer language | Update messages, feedback request, apology | A2 with templates, A1 for free text |

Give each agent its own OpenClaw workspace and binding, with its own allow list. The Registration and Customer agents must not have spare-stock write proposals; the Spares agent must not see customer contact details beyond what it needs.

### 4.4 Structured output and fallback

Each agent has a Pydantic output schema. The runtime returns validated output only. If validation fails twice, the core places the item in a human queue with the reason `agent_invalid_output` and continues. If the runtime is down, allocation falls back to the rules scorer (section 7.2) and registration falls back to the web form flow.

---

## 5. Domain model

Use these as the starting schema. Ask before changing an entity.

### 5.1 Masters

- `tenant`, `brand`, `product_model` (category, model code, warranty months, install required, spares BOM link).
- `spare_part` (part code, name, category, uom, is_serialised, is_returnable, cost, mrp, warranty_claimable, substitute_group).
- `bom` (product_model_id, part_id, qty, criticality).
- `fault_code`, `action_code`, `symptom` (per category; used in triage and closure).
- `pincode` (pincode, city, state, tier, lat, lon).
- `sla_policy` (tenant, category, tier, call_type, priority, response_min, visit_min, closure_min).
- `escalation_matrix` (level, role, trigger, wait_min, channel).
- `notification_template` (channel, name, language, body, variables, bsp_template_id, category).

### 5.2 Parties

- `customer` (name, phones, language, consent flags, addresses with geo).
- `customer_asset` (customer, product_model, serial_no, purchase_date, dealer, invoice_ref, warranty_start, warranty_end, extended_until, amc_id).
- `service_partner` (type: `company` or `franchisee`, name, gstin, status, territory_ids, owns_stock, payout_terms).
- `engineer` (partner_id, name, phone, skills[], grade, home_geo, van_location_id, status, languages, whatsapp_verified_at, device_last_seen).
- `service_area` (partner_id, pincodes[], categories[], priority_rank, capacity_per_day).

### 5.3 Transactions

- `call` (call_no, customer_id, asset_id, type: `installation | repair | pm | demo | complaint`, channel, fault_code, symptom_text, priority, warranty_status, chargeable, status, sla_due_at, created_at, parent_call_id).
- `call_event` (call_id, type, payload, actor, at, idempotency_key). Append-only.
- `allocation` (call_id, partner_id, engineer_id, method: `auto | manual | reallocation`, score, reasons[], status, offered_at, responded_at, expires_at).
- `visit` (call_id, engineer_id, planned_slot, eta, checked_in_at, checked_in_geo, started_at, ended_at, outcome).
- `job_card` (call_id, diagnosis, action_codes[], parts_used[], checklist_answers, photos[], customer_otp_verified, customer_signature, engineer_notes, charges[]).
- `closure` (call_id, submitted_at, agent_verdict, flags[], reviewer_id, final_status, reason).
- `message_log` (channel, direction, actor, call_id, wa_message_id, template_id, status, media_ids[], text_redacted, at).
- `agent_run` (agent_id, run_id, call_id, input_hash, output_json, reasoning_summary, tool_trace, cost, latency_ms, status, approved_by).
- `approval` (subject_type, subject_id, requested_by, approver_role, decision, reason, at).

### 5.4 Spares

- `stock_location` (type: `central_warehouse | branch_store | franchisee_store | engineer_van | defective_bin`, partner_id, owner: `company | franchisee`).
- `stock_ledger` (part_id, location_id, qty_delta, type: `receipt | transfer_out | transfer_in | reserve | release | issue | consume | return_good | return_defective | adjust | dead_on_arrival`, ref_type, ref_id, at, actor). **Append-only.** On-hand and reserved are projections of this table.
- `part_request` (call_id, engineer_id, part_id, qty, urgency, source_suggested, status, reserved_from_location_id, eta).
- `part_reservation` (part_request_id, location_id, qty, expires_at).
- `part_consumption` (call_id, part_id, qty, serial_old, serial_new, defective_tag_id, photo_ids[]).
- `defective_return` (tag_id, part_id, call_id, from_engineer_id, status, received_at, inspected_result, claim_id).
- `replenishment_rule` (location_id, part_id, min, max, lead_time_days, preferred_source).
- `warranty_claim` (partner_id, call_id, parts[], labour, status, submitted_at, decided_at, amount, decision_reason).

### 5.5 Money (light in v1)

- `charge` (call_id, type: `visit | labour | part | travel | discount`, amount, tax, payer: `customer | company`, payment_status, payment_ref).
- `partner_payout` (partner_id, period, calls[], amount, deductions[], status).

---

## 6. Call lifecycle

### 6.1 States

```
NEW -> REGISTERED -> TRIAGED -> READY_TO_ALLOCATE -> OFFERED -> ALLOCATED
   -> ACCEPTED -> SCHEDULED -> EN_ROUTE -> ON_SITE -> IN_PROGRESS
   -> PART_PENDING -> (back to SCHEDULED after part arrives)
   -> CLOSURE_SUBMITTED -> CLOSURE_VERIFIED -> CLOSED
Side states: ON_HOLD (customer), REALLOCATING, ESCALATED, CANCELLED, REOPENED, DUPLICATE
```

### 6.2 Transition rules (implement as data, not scattered ifs)

| From | To | Guard | Side effects |
|---|---|---|---|
| NEW | REGISTERED | Customer, product, fault text present | Create call number, start response SLA, send acknowledgement |
| REGISTERED | TRIAGED | Fault code and priority set | Attach likely parts and skill |
| TRIAGED | READY_TO_ALLOCATE | Serviceable pincode, warranty decision made | Enqueue allocation |
| READY_TO_ALLOCATE | OFFERED | Allocation proposal passes policy | Send offer to franchisee or engineer, start acceptance timer |
| OFFERED | ALLOCATED | Accepted, or auto-assigned by policy | Notify customer with engineer name and slot |
| OFFERED | REALLOCATING | Rejected or timeout | Exclude the partner or engineer, rerun allocation |
| ALLOCATED | ACCEPTED | Engineer accepts | Lock slot |
| ACCEPTED | EN_ROUTE | Engineer taps "Start travel" | Send ETA to customer |
| EN_ROUTE | ON_SITE | Location shared within geo-fence or manual override with reason | Start visit timer |
| ON_SITE | IN_PROGRESS | Engineer taps "Start job" and asset serial confirmed | |
| IN_PROGRESS | PART_PENDING | Part request approved and no stock on hand | Reserve or transfer, set customer expectation |
| IN_PROGRESS | CLOSURE_SUBMITTED | Closure checklist complete | Closure agent runs |
| CLOSURE_SUBMITTED | CLOSURE_VERIFIED | Agent verdict clean, or reviewer approves | Reconcile parts, request customer OTP if not done |
| CLOSURE_VERIFIED | CLOSED | Customer OTP verified or auto-close window passed under policy | Send feedback request, create claim, release resources |
| CLOSED | REOPENED | Customer complaint within repeat window (default 7 days) | Link as repeat, raise priority, notify supervisor |

### 6.3 SLA clocks

Start and stop clocks on states: response, acceptance, arrival, closure, part-arrival. Pause on `ON_HOLD (customer)`. Store `sla_due_at` per clock and expose `time_remaining`. Defaults are policy rows in `sla_policy`, not code. **[ASSUMPTION]** Example starting values: acknowledgement within 5 minutes, acceptance within 15 minutes, visit within 24 hours for Tier-1 and 48 hours for Tier-2, closure within 72 hours excluding part wait.

### 6.4 Escalation ladder (defaults, stored in `escalation_matrix`)

| Level | Trigger | Who | Channel |
|---|---|---|---|
| L0 | 50% of clock elapsed with no progress | Assigned engineer | WhatsApp nudge |
| L1 | 80% elapsed | Franchisee dispatcher or branch supervisor | WhatsApp and console alert |
| L2 | Clock breached | Branch service manager | WhatsApp and console alert |
| L3 | Breached by 24 h or customer complaint | Regional service head | WhatsApp digest and call |
| L4 | Repeat failure or safety issue | National head | Immediate alert |

Escalations are events. Each acknowledgement or action stops the ladder. Never escalate a paused clock.

---

## 7. Modules

### 7.1 Call registration

**Channels:** WhatsApp (customer to the brand's business number), web form, call-centre agent screen, dealer link. Phone calls arrive as agent-entered or transcribed calls in v1.

**Registration agent behaviour (WhatsApp):**

1. Greet in the customer's language (detect from first message; offer English, Hindi and configured regional languages).
2. Identify the customer by phone. If unknown, collect name and pincode.
3. Identify the product: ask the customer to pick from their registered products, send a photo of the serial or model plate (OCR), or type a model.
4. Understand the fault from text, voice note or photo. Ask at most three clarifying questions, one at a time, using buttons where the answers are finite.
5. Check for an open call on the same asset. If one exists, offer status instead of a new call.
6. Determine warranty status and whether the visit is chargeable. Tell the customer the visit charge before booking if chargeable.
7. Confirm address and preferred slot. Create the call and send a confirmation with the call number.
8. Hand over to a human if: the customer asks for one, two clarifications fail, the customer is angry (sentiment flag), or the issue is a safety hazard (gas smell, sparking, burning).

**Rules:**

- Duplicate detection: same asset with an open call in the last N days (default 3), or same phone with the same fault text similarity above a threshold. Ask before merging; never silently merge.
- Safety keywords trigger priority `P0` and an immediate human alert.
- The agent never promises a time; it offers slots that the core has already validated.
- Consent: capture WhatsApp opt-in and DPDP consent text on first contact.

### 7.2 Allocation to franchisee or company engineer

**Inputs:** call location (geo-coded), category, fault skill, priority, SLA due, warranty status, customer language, existing load, engineer status, partner rules, stock availability for likely parts.

**Two-step decision:**

1. **Choose the owner** (`company` or `franchisee`) using the `service_area` and `owner_policy`. Policy examples: territory ownership by pincode and category; company engineers handle installation of premium products; franchisee handles out-of-warranty in Tier-2; overflow rule sends to the other owner when the primary is over capacity. Policy is data.
2. **Choose the engineer** inside the owner:
   - If the franchisee has enabled **auto-assign**, the system chooses.
   - Otherwise the franchisee dispatcher receives an offer with a recommended engineer and accepts or reassigns from WhatsApp or the portal.

**Candidate generation (hard filters):** serviceable pincode, category authorised, active and on shift, not on leave, not blocked by open disciplinary flag, phone verified.

**Scoring (soft, weights are config):**

```
score = w1*proximity + w2*skill_match + w3*load_headroom + w4*first_time_fix_rate
      + w5*parts_on_van + w6*language_match + w7*customer_history_affinity
      - w8*recent_breach_penalty
```

The allocation agent explains its choice in two lines. The deterministic scorer produces a ranked list; the agent may re-rank within a small window only with a stated reason. The core rejects an agent pick that violates a hard filter.

**Offer flow and timers:**

- Franchisee or engineer gets a WhatsApp offer: call summary, locality (not full address until accepted), slot, likely parts, buttons Accept, Reject with reason, Need help.
- Acceptance timeout default 15 minutes for P1, 30 minutes for P2. On timeout, exclude and re-run. After two rejections, escalate to L1.
- Reallocation triggers: rejection, timeout, engineer marks unavailable, running late beyond threshold, part not on van and another engineer has it, customer requests change.
- Keep continuity: prefer the same engineer for a return visit after part arrival.

### 7.3 Engineer workflow on WhatsApp

**Identity:** engineers are pre-registered by phone. First contact requires a one-time link and a 4-digit PIN. The bridge maps the sender number to an `engineer` row. Unknown numbers get a polite refusal.

**Daily rhythm:**

| Moment | Bot message | Engineer action |
|---|---|---|
| Start of shift | Job list for today with order, time windows and part readiness | Tap "Start day", share location |
| New offer | Offer card | Accept, Reject, Call dispatcher |
| Before visit | Reminder, customer name, address, product, fault, parts on van check | Tap "Start travel" |
| On the way | Auto ETA to customer | |
| Arrival | Ask to share live location or location pin | Share location, geo-fence check |
| Start job | Ask to scan or photograph serial plate; confirm model | Photo, confirm |
| Diagnosis | Field assistant offers likely causes and steps on request | Reply with findings |
| Parts needed | Part picker from BOM and likely parts; shows availability at van, nearby engineer, branch | Select parts, urgency |
| Closure | Guided checklist (see below) | Answer step by step |
| End of day | Summary, pending items, tomorrow preview | Confirm |

**Commands (also as a menu list):** `JOBS`, `START`, `ARRIVED`, `PART`, `HOLD`, `CLOSE`, `HELP`, `SOS`. Free text and voice notes are interpreted by the Field assistant; when it is unsure it asks one short question with buttons.

**Closure checklist (order matters, each step is validated before the next):**

1. Confirm asset serial (photo plus OCR match to the call).
2. Select fault found (from `fault_code` list, suggested first).
3. Select action taken (from `action_code` list).
4. Parts used: pick from issued or on-van parts; enter quantity; photograph the new part label; for returnable parts, photograph the old part and tag number.
5. Test result: yes or no with a photo or short video of the working unit.
6. Charges: if chargeable, show the amount and payment method; record payment reference.
7. Customer OTP: bot asks the engineer to request the OTP; the customer receives it on their WhatsApp or SMS; engineer enters it. Fallback: customer taps "Confirm" in their own chat.
8. Engineer notes (text or voice).
9. Submit.

**Mandatory fields depend on call type** (`installation` needs demo done and installation photo; `pm` needs checklist; `repair` needs parts reconciliation). Store the rule set in `closure_rule` rows, not in code.

### 7.4 Closure verification (Closure agent plus rules)

Run deterministic checks first, then the agent for judgment.

**Deterministic checks (fail means flag, not auto-reject):**

- Geo: check-in within the fence (default 300 m of the customer geo) **[ASSUMPTION]**.
- Time on site: below a minimum or above a maximum for the fault type.
- Serial photo OCR equals asset serial.
- Parts: every consumed part was issued to this engineer or reserved for this call; quantities match; serialised parts have old and new serials; defective tag exists for returnable parts.
- OTP verified, or a documented exception.
- Photo integrity: perceptual-hash duplicates against past closures, EXIF time and device consistency, screenshot detection.
- History: same fault code closed on this asset within the repeat window (potential false closure).

**Agent judgment (structured output):**

```json
{
  "verdict": "accept | review | reject",
  "confidence": 0.0,
  "flags": ["duplicate_photo", "time_on_site_low", "parts_mismatch"],
  "reason": "two-sentence explanation",
  "questions_for_engineer": ["..."],
  "suggested_action": "close | request_more_proof | call_customer | supervisor_review"
}
```

**Outcomes:** clean and high confidence goes to `CLOSURE_VERIFIED` automatically under tier A2. Any flag sends it to the supervisor queue with the reason. A reject returns to the engineer over WhatsApp with exactly what is missing.

### 7.5 Spare parts management

**Ledger first.** All movement is a ledger entry. On-hand, reserved and available are projections. Never update a balance in place.

**Locations:** central warehouse, branch stores, franchisee stores, engineer vans, defective bins. Ownership matters: `company`-owned stock is issued free; `franchisee`-owned stock is purchased or on consignment and settles through payout.

**Flow:**

1. **Predict at triage.** The Triage agent proposes likely parts from fault code, model BOM and similar past calls (pgvector). Show availability at each level before allocation.
2. **Request.** Engineer requests parts on WhatsApp during diagnosis. The Spares agent picks the best source: own van, nearby engineer, franchisee store, branch, warehouse. It proposes reserve or transfer.
3. **Reserve.** Core creates `part_reservation` with expiry. Reservation is released on cancel, expiry or when the call closes.
4. **Issue and dispatch.** For parts from a store, record issue to engineer or courier. Track ETA. Notify engineer and customer.
5. **Consume.** On closure, consumption converts reserved or issued stock to consumed. Reject consumption of a part never issued.
6. **Return.** Unused good parts return to the van or store. Defective parts return with a tag through `defective_return`, inspected, and linked to a warranty claim.
7. **Replenish.** Reorder rules per location. The agent proposes transfers before purchase orders and drafts purchase requisitions for approval.
8. **Substitution.** If a part is out of stock, propose approved substitutes from `substitute_group`.
9. **DOA (dead on arrival).** A new part failing at install triggers `dead_on_arrival`, a replacement request and a supplier claim.

**Monitoring rules:** part-pending ageing, stock-out on fast movers, van stock accuracy, engineers with high unreturned defectives, consumption outliers per engineer or franchisee, cannibalisation between calls, negative stock attempts (hard block).

**Forecast:** weekly demand per part per region using call history, seasonality (for example AC before summer) and installed base. Start with a simple statistical baseline; log forecast error; do not let an LLM generate numbers that the baseline cannot reproduce.

### 7.6 Monitoring and control tower

The Monitor agent and the analytics projections serve five levels.

| Level | Sees | Key measures |
|---|---|---|
| Engineer | Own jobs, parts on van, day plan | Jobs done, first-time-fix, repeat rate, part-pending, CSAT |
| Franchisee | Own engineers, own stock, claims | SLA compliance, backlog ageing, closure quality flags, stock-outs, claim approval rate, payout preview |
| Branch | Franchisees and company engineers in branch | Same as franchisee plus rejection and reallocation rate, parts fill rate |
| Region | Branches | Call volume vs forecast, cost per call, warranty cost, DOA rate, escalation count |
| National | Regions, categories, models | Repeat call rate by model, mean time to close, spare turns, franchisee league table, safety incidents |

**Silent-failure detectors** (each is a rule plus an agent summary):

1. **Offered, unanswered:** offer sent, no response, clock still running.
2. **Allocated, no movement:** accepted call with no travel start near the slot.
3. **On site, no progress:** long time on site with no update, no part request.
4. **Part pending, no movement:** reserved part not dispatched or not received.
5. **Ownerless:** call touched by several people or channels with no single owner.
6. **Closed, not verified:** closure submitted and waiting too long for review.
7. **Repeat risk:** same asset with two calls in the repeat window.

**Outputs:** WhatsApp nudge to the responsible person, console alert, escalation event, and a daily and weekly digest written by the agent and checked against the numbers (every figure in a digest is computed by SQL, the agent only writes prose).

### 7.7 Customer communication and feedback

- Templates for acknowledgement, allocation, ETA, delay apology, part pending, closure OTP, feedback, reopen.
- Free-text customer replies go to the Registration or Customer agent; keep them inside the 24-hour customer-service window or use approved templates outside it.
- CSAT (1 to 5) after closure. Low scores create a follow-up task and count toward repeat-risk.
- Never share the engineer's personal number; use a masked bridge or in-chat relay **[ASSUMPTION]**.

### 7.8 Franchisee claims and payout (light v1)

- On closure, create a `warranty_claim` with parts, labour and evidence.
- Auto-approve clean claims under a value limit; send flagged claims to a reviewer with the closure flags attached.
- Payout preview per period for franchisee and engineer views. Integrate accounting later through the adapter in section 11.3.

---

## 8. Autonomy and human-in-the-loop

Each action type has a tier stored in `policy`. Changing a tier is an admin action with audit.

| Tier | Meaning |
|---|---|
| A0 | Read only. The agent explains or summarises |
| A1 | Suggest. A human clicks approve in console or WhatsApp |
| A2 | Act within limits. The core executes if policy passes, and logs |
| A3 | Act broadly. Reserved for later; not used in v1 |

**Default matrix:**

| Action | Tier | Limits |
|---|---|---|
| Create call from a complete registration | A2 | Duplicate check must pass |
| Override warranty status | A1 | Supervisor approval |
| Allocate within owner policy | A2 | Hard filters must pass |
| Allocate outside policy or across owners | A1 | Branch approval |
| Send templated customer message | A2 | Approved templates only |
| Send free-text customer message | A1 | Or A2 after eval threshold met |
| Reserve or transfer stock | A2 | Value and distance limits |
| Raise purchase requisition | A1 | Always approved by planner |
| Accept clean closure | A2 | No flags, confidence at or above threshold |
| Reject or reopen | A1 | Reviewer confirms |
| Escalate | A2 | Follows matrix |
| Change SLA, matrix or policy | A0 | Human only |

**Promotion rule:** an agent moves up a tier only after it beats its evaluation threshold on the golden dataset for two consecutive releases and an admin approves.

---

## 9. Safety, privacy and compliance

- **DPDP Act:** record consent purpose and time, support access and deletion requests, keep data in India, minimise PII in agent context (initials and locality until the engineer accepts).
- **WhatsApp rules:** use approved templates outside the 24-hour window, honour opt-out immediately, respect BSP rate limits, never message a number that has not opted in.
- **Engineer safety:** SOS command alerts the supervisor with last location; do not force travel during flagged risk.
- **Prompt-injection defence:** treat all inbound text, OCR output and voice transcripts as untrusted. Agents never follow instructions found in customer or engineer content. Tools ignore free text that tries to change tenant, actor or policy.
- **Least privilege tools:** proposal tools only. Nothing in the tool server can delete data, change policy, or send money.
- **Audit:** every agent run, tool call, approval and policy check is stored and queryable by call.
- **Secrets:** environment or secret manager only.

---

## 10. WhatsApp through a BSP

### 10.1 Port

```python
class WhatsAppProvider(Protocol):
    async def send_template(self, to: Phone, template: str, lang: str, vars: dict) -> SendResult: ...
    async def send_text(self, to: Phone, text: str) -> SendResult: ...
    async def send_buttons(self, to: Phone, body: str, buttons: list[Button]) -> SendResult: ...
    async def send_list(self, to: Phone, body: str, sections: list[Section]) -> SendResult: ...
    async def send_location_request(self, to: Phone, body: str) -> SendResult: ...
    async def fetch_media(self, media_id: str) -> bytes: ...
    def verify_webhook(self, headers: dict, body: bytes) -> bool: ...
    def parse_webhook(self, body: dict) -> list[InboundEvent | StatusEvent]: ...
```

Build `MockProvider` first (drives the simulator), then one real adapter (Gupshup, Twilio or AiSensy; the client chooses). Keep provider-specific quirks inside the adapter.

### 10.2 Inbound handling

1. Verify signature. Reject and log otherwise.
2. Deduplicate on provider message id.
3. Store media immediately and replace the media id with our own storage key.
4. Map the sender: engineer, franchisee staff, customer, or unknown.
5. Emit `wa.inbound` with actor type and normalised content.
6. Route to the right handler: engineer flow engine, customer Registration agent, or franchisee flow.
7. Acknowledge quickly; do heavy work asynchronously.

### 10.3 Conversation state

Persist a `conversation_state` per (actor, purpose): current flow, step, collected fields, expiry. The **flow engine** (deterministic) walks engineer flows step by step. Agents help interpret free text, voice and images, but the flow engine decides the next step and validates each answer.

### 10.4 Templates to register (starting list)

Customer: `call_registered`, `engineer_assigned`, `eta_update`, `delay_apology`, `part_pending`, `closure_otp`, `closure_confirm`, `feedback_request`, `reopened_ack`.
Engineer and franchisee: `job_offer`, `job_reminder`, `escalation_nudge`, `part_dispatched`, `daily_summary`, `claim_status`.

Every template has English and Hindi at minimum, with regional languages per tenant.

### 10.5 Delivery and failure handling

Track `sent`, `delivered`, `read`, `failed`. On failed critical messages (offer, OTP), retry through SMS or call the dispatcher **[ASSUMPTION]**. Keep a per-actor rate limit to avoid spam.

---

## 11. APIs and events

### 11.1 Event names (append-only, versioned)

`call.registered`, `call.triaged`, `call.ready_to_allocate`, `allocation.offered`, `allocation.accepted`, `allocation.rejected`, `allocation.timed_out`, `visit.travel_started`, `visit.checked_in`, `visit.started`, `part.requested`, `part.reserved`, `part.issued`, `part.consumed`, `part.returned`, `defective.received`, `closure.submitted`, `closure.verified`, `closure.rejected`, `call.closed`, `call.reopened`, `sla.warning`, `sla.breached`, `escalation.raised`, `escalation.acknowledged`, `wa.inbound`, `wa.status`, `agent.run.completed`, `approval.requested`, `approval.decided`.

### 11.2 Internal API (illustrative)

```
POST /calls                       register (used by bridge and console)
GET  /calls/{id}                  full timeline
POST /calls/{id}/transition       guarded transition
POST /allocations/{id}/respond    accept | reject
POST /calls/{id}/parts/requests
POST /calls/{id}/closure          submit
GET  /engineers/{id}/day
GET  /stock/availability?part=...&near=...
GET  /kpi/{level}/{id}
POST /approvals/{id}/decide
```

### 11.3 Adapters (interfaces only in v1)

`ErpAdapter` (stock receipts, purchase requisitions, invoices), `CrmAdapter` (customer master sync), `PaymentAdapter`, `SmsAdapter`. Provide file-based and mock implementations so v1 runs standalone.

---

## 12. Web console

| Screen | Purpose |
|---|---|
| Call desk | Register and search calls, see timeline, take over from the bot |
| Dispatch board | Map and list of calls, engineers, status, drag to reassign |
| Approvals inbox | Everything at tier A1: allocations outside policy, warranty overrides, flagged closures |
| Control tower | KPIs by level with drill-down; live silent-failure panel with the seven detectors |
| Franchisee portal | Own calls, engineers, stock, claims, payouts |
| Spares | Availability, transfers, reservations, defectives, replenishment, forecast vs actual |
| Engineer profile | Skills, performance, stock on van, last seen |
| Admin | Masters, SLA, escalation matrix, policies, templates, agent settings and tiers |
| Agent runs | Inspect any agent decision with input, tool trace, reasoning summary and cost |

Design notes: table first, map second; colour only for state (green on track, amber at 80%, red breached); every alert has an action button; keyboard friendly.

---

## 13. Non-functional requirements

- **Latency:** WhatsApp acknowledgement under 2 seconds; agent response under 10 seconds for interactive turns; allocation proposal under 20 seconds.
- **Availability:** the deterministic core and the flow engine keep working if the agent runtime is down; registration and allocation degrade to forms and the rules scorer.
- **Scale target v1 [ASSUMPTION]:** 5,000 calls per day, 2,000 active engineers, 50,000 messages per day.
- **Idempotency and ordering:** per-call ordering key; replay of any webhook produces no duplicate effect.
- **Observability:** trace id from webhook to agent to core; dashboard for queue depth, agent latency, invalid-output rate, fallback rate, cost per call.
- **Cost:** track LLM and OCR cost per call and per agent; alert on drift; cache read-only lookups.
- **Security:** RLS tenant isolation, encrypted storage, presigned media URLs with short expiry, quarterly access review, secrets rotation.
- **Data retention:** configurable per tenant; media retention shorter than records.

---

## 14. Phased build plan with acceptance tests

Do not start a phase until the previous phase's acceptance tests pass.

### Phase 0: Foundations
Repo, CLAUDE.md, docker-compose (Postgres with PostGIS, Redis, MinIO), Alembic baseline, tenant and RLS, auth, event log and projection scaffolding, `AgentRuntime` port with stub, `WhatsAppProvider` port with mock, WhatsApp simulator skeleton, CI (ruff, mypy, pytest).
**Accept:** tenant isolation test passes; a replayed event creates no duplicate; simulator can send and receive through the mock provider.

### Phase 1: Registration
Masters and seed data, customer and asset model, warranty engine, call model and state machine (through TRIAGED), registration flow on WhatsApp (buttons, photos, voice notes with stubbed STT), duplicate detection, call desk screen, Registration and Triage agents on stub then OpenClaw.
**Accept:** 20 scripted customer conversations in the simulator register correct calls; duplicate and safety scenarios behave per section 7.1; agent invalid output falls back to the form.

### Phase 2: Allocation
Service areas, owner policy, candidate filters, scorer, offer flow with timers, franchisee accept and reject, reallocation, manual override, dispatch board, Allocation agent (agent may only re-rank within policy).
**Accept:** allocation golden set of 50 calls matches the expected owner in at least 90% of cases; timeout and rejection reallocate correctly; a hard-filter violation proposed by the agent is rejected by the core.

### Phase 3: Engineer WhatsApp workflow
Engineer identity and PIN, day plan, offer, travel, arrival with geo-fence, job start with serial OCR, HOLD, SOS, voice notes, Field assistant, flow engine with resumable state.
**Accept:** full journey from offer to job start runs in the simulator; interrupting and resuming a flow works; unknown numbers are refused; geo-fence exceptions require a reason.

### Phase 4: Spares
Locations, ledger, projections, part request from WhatsApp, availability search, reserve, transfer, issue, consume, return, defective tags, replenishment rules, Spares agent, spares screens.
**Accept:** ledger balances reconcile after a randomised 1,000-movement test; consuming an unissued part is blocked; a part-pending call resumes when the part is received; substitution suggestions respect approved groups.

### Phase 5: Closure
Closure checklist flow, deterministic checks, customer OTP, Closure agent, reviewer queue, reopen and repeat linkage, claim creation.
**Accept:** 30 seeded closures (10 clean, 10 flawed, 10 fraudulent patterns) are classified correctly at or above the threshold set with the client; flagged closures never auto-close.

### Phase 6: Monitoring
SLA clocks, escalation ladder, seven detectors, KPIs per level, control tower, daily and weekly digests, Customer comms agent, CSAT.
**Accept:** injected silent failures are detected within one detector cycle; no escalation fires on a paused clock; every digest figure matches SQL.

### Phase 7: Hardening and pilot readiness
Real BSP adapter, load test, security review, DPDP checklist, runbooks, pilot playbook for one branch and two franchisees, agent evals wired into CI as a release gate.
**Accept:** load test meets section 13; pilot checklist signed off; rollback plan tested.

---

## 15. Testing and simulation

- **WhatsApp simulator:** a web chat UI that impersonates customers, engineers and franchisee staff through the mock provider, with scripted scenarios in YAML (messages, media, delays).
- **Synthetic data:** one tenant, three regions, 200 pincodes, 20 franchisees, 300 engineers, 40 product models, 800 parts, 10,000 historical calls with realistic faults, repeat patterns, part delays and a few fraud patterns.
- **Evals (per agent):** golden datasets in `tests/evals/`, each case with input, expected structured output and a tolerance. Metrics: allocation match rate, registration completion rate, triage fault-code accuracy, closure precision and recall on flawed cases, digest number accuracy (must be 100%).
- **Chaos tests:** runtime down, BSP timeout, duplicate webhooks, out-of-order messages, engineer offline for hours, part receipt before issue.
- **Human review sample:** weekly random sample of A2 decisions reviewed by a supervisor; disagreements feed the golden set.

---

## 16. Open questions and assumptions to confirm before Phase 0

1. Which product categories are in scope first (for example refrigerators, washers, ACs)? This decides the fault codes and BOMs to seed.
2. Franchisee model: do franchisee engineers use the brand's WhatsApp bot, or does the franchisee dispatcher assign by phone? Who owns the stock and how does settlement work?
3. Which BSP will the client use, and are the WhatsApp business number and templates already approved?
4. Are there existing systems to integrate first (ERP for stock and invoices, a call-centre telephony system)?
5. Warranty rules: source of truth for purchase date and warranty extension; how are extended warranty and AMC represented?
6. SLA values and escalation contacts by region.
7. Languages needed for customer and engineer messages.
8. Model and runtime hosting: which model endpoints are allowed under the client's data policy, and must everything run in India?
9. Charges: is a visit charge collected on WhatsApp or by the engineer, and through which payment provider?
10. Volume assumptions in section 13.
11. Data migration: is there historical call data to import for forecasting and evaluation?
12. Approval authorities by level for A1 actions.

---

## 17. Definition of done for the whole programme

- A customer can register a call on WhatsApp and receive a confirmed engineer and slot without a human touching it, for the simple cases in scope.
- A franchisee or company engineer can complete a normal job from offer to verified closure using only WhatsApp.
- Every spare part movement reconciles in the ledger and every consumed part has an issued source.
- Supervisors at each level can see their own SLA, backlog and stock position live, and are alerted before a breach.
- Every agent decision is explainable, replayable and bounded by the autonomy matrix.
- The system runs in degraded mode when the agent runtime is down.
