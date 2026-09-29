# Phase plan, risks and blocking questions

Status: proposal for approval. No application code has been written yet (spec 0.1 rule 1).

## Phases (spec 14)

| Phase | Scope | Exit gate (acceptance tests) |
|---|---|---|
| 0 Foundations | Monorepo skeleton, `infra/docker-compose.yml` (Postgres+PostGIS+pgvector, Redis, MinIO), Alembic baseline, tenant + RLS, auth, event log + projection scaffold, `AgentRuntime` port + stub, `WhatsAppProvider` port + mock, simulator skeleton, CI (ruff, mypy, pytest) | Tenant isolation test; replayed event creates no duplicate; simulator sends/receives via mock |
| 1 Registration | Masters + seed, customer/asset, warranty engine, call state machine to TRIAGED, WhatsApp registration flow (stub STT), duplicate detection, call desk, Registration + Triage agents (stub, then OpenClaw) | 20 scripted conversations register correct calls; duplicate + safety scenarios per 7.1; invalid agent output falls back to form |
| 2 Allocation | Service areas, owner policy, filters, scorer, offer flow + timers, accept/reject, reallocation, manual override, dispatch board, Allocation agent (re-rank only) | Golden set of 50 calls >= 90% owner match; timeout/rejection reallocate; agent hard-filter violation rejected by core |
| 3 Engineer WhatsApp | Identity + PIN, day plan, offer, travel, geo-fence arrival, job start with serial OCR, HOLD, SOS, voice, Field assistant, resumable flow engine | Offer-to-job-start journey in simulator; interrupt/resume; unknown numbers refused; geo exceptions need reason |
| 4 Spares | Locations, append-only ledger + projections, part requests, availability, reserve/transfer/issue/consume/return, defective tags, replenishment, Spares agent, screens | Ledger reconciles after randomised 1,000 movements; unissued consumption blocked; part-pending resumes on receipt; substitutes respect groups |
| 5 Closure | Checklist flow, deterministic checks, OTP, Closure agent, reviewer queue, reopen/repeat, claims | 30 seeded closures classified at agreed threshold; flagged never auto-close |
| 6 Monitoring | SLA clocks, escalation ladder, 7 detectors, KPIs per level, control tower, digests, Customer comms agent, CSAT | Injected failures detected in one cycle; no escalation on paused clock; digest figures match SQL |
| 7 Hardening | Real BSP adapter, load test, security review, DPDP checklist, runbooks, pilot playbook, evals as CI gate | Load test meets spec 13; pilot checklist; rollback tested |

## Risks

1. **OpenClaw API shape unverified.** Mitigation: verify docs first (`docs/openclaw-notes.md`); everything sits behind `AgentRuntime`, `StubRuntime` and `RulesOnlyRuntime` keep the system working regardless.
2. **Scope is very large** (8 phases, 9 agents, React console). Mitigation: strict gates; Phase 0-2 deliver a usable slice (register to allocate).
3. **RLS + async SQLAlchemy** session-variable handling is easy to get wrong. Mitigation: isolation test is the first Phase 0 gate; use `SET LOCAL` per transaction.
4. **Durable timers** (SLA, acceptance) behind a `TimerService` interface; Celery+Redis first, Temporal-swappable. Risk of lost timers on Redis restart: persist timers in Postgres and let Celery only fire.
5. **WhatsApp template approval** takes days with a real BSP; not blocking until Phase 7.
6. **Data residency** may constrain which model endpoints can be used; decide before Phase 1 agents go live on OpenClaw.
7. **Repo hygiene:** CRM is being added to a repo that already contains MiroFish (see question A).

## Questions that block Phase 0 (need your decision)

A. **Repo placement.** This repo (`anurag42c/mirofish`) holds an unrelated project. Options: (1) build the CRM here in `apps/ packages/ infra/` next to MiroFish (my default if you say nothing), (2) create a new dedicated repository. Option 2 is cleaner; I need repo access added for it.
B. **Stack confirmations** (spec 3.2 defaults, proceeding unless you object): Python 3.12, FastAPI, SQLAlchemy 2, PostgreSQL 16 + PostGIS + pgvector, Celery+Redis, MinIO for local S3, `uv` for packaging, pnpm/Vite for console.
C. **Spec section 16 items that affect Phase 0 design** (I will use the spec's assumptions as configurable defaults where not answered):
   - Q2 Franchisee model (do franchisee engineers use the bot; who owns stock): affects `service_partner.auto_assign` and stock ownership seeding (Phase 2 and 4, not Phase 0).
   - Q3 BSP choice: only Phase 7; Phase 0-6 use the mock.
   - Q8 Model/runtime hosting and India residency: needed before Phase 1 goes live on OpenClaw; stub until then.
   - Q1 Product categories: needed for Phase 1 seed data; default to refrigerators, washers, ACs.
   - Remaining items (Q4-7, 9-12) do not block Phase 0.

Phase 0 is not blocked by any of these except A. On your go-ahead I will start Phase 0.
