# Agentic Service Management CRM: build rules

Full specification: `Agentic-Service-CRM-Build-Spec.md` (draft v0.1). Read the relevant section before touching a module. Phase plan and open questions: `docs/phase-plan.md`.

> Note: this repository also contains the unrelated MiroFish project (`backend/`, `frontend/`, root `docker-compose.yml`). The CRM lives in `apps/`, `packages/`, `tools/`, `infra/`, `tests/`. Do not modify MiroFish files.

## Non-negotiable engineering rules (spec 0.2)

- Python 3.12, type hints everywhere, `ruff` and `mypy --strict` clean, `pytest` with a coverage gate on core packages.
- Pydantic v2 models at every boundary: API, events, agent inputs/outputs, tool arguments.
- Every state change is an event first. State = event log + projection tables.
- Every side effect is idempotent and carries an `idempotency_key`. Webhooks are safe to replay.
- Tenant and actor identity come from request or run context, never from model-generated arguments.
- No PII in logs. Mask phones (`+91******1234`).
- All LLM/agent calls go through the `AgentRuntime` port. No direct SDK calls in domain code.
- Every agent output is schema-validated. Invalid output: one retry, then rule fallback or human queue.
- Alembic migrations only. No manual schema edits.
- Every agent needs an eval dataset and pass threshold before going above autonomy tier A1.

## Working rules

1. Agents propose, the core disposes. Agents never write to the database directly.
2. The deterministic core (state machine, stock ledger, SLA timers, policy engine) is the source of truth.
3. Build strictly phase by phase (spec 14). Do not start a phase until the previous phase's acceptance tests pass; show test output.
4. Verify OpenClaw against https://docs.openclaw.ai before integrating; record findings in `docs/openclaw-notes.md`. Do not assume API shapes.
5. Use the mock WhatsApp provider and simulator for all dev and tests. No real BSP credentials in the repo.
6. Ask the user before changing the data model (spec 5) or autonomy tiers (spec 8).

## Repo layout (spec 3.4)

```
CLAUDE.md, Agentic-Service-CRM-Build-Spec.md
docs/            openclaw-notes.md, adr/, runbooks/, phase-plan.md
apps/            api/ (FastAPI)  worker/ (Celery, timers)  bridge/ (webhooks, outbound)  console/ (React)
packages/
  core/          calls/ allocation/ stock/ sla/ policy/ closure/
  domain/        entities, pydantic schemas, events
  agents/        runtime/ (port, openclaw, stub)  tools/  registration/ allocation/ dispatch/ closure/ spares/ monitor/ customer/
  channels/      whatsapp/ (port, mock, gupshup, twilio, aisensy)  voice/ ocr/ storage/
  analytics/
tests/           unit/ integration/ e2e/ evals/
tools/           whatsapp_simulator/  seed/
infra/           docker-compose.yml, helm/
```
