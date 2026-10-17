# ADR 0001 — Modular monolith with contract-based module boundaries

- Status: Accepted · 2026-10-17 · Owner: CTO

## Context
A hosting billing platform spans ~10 business domains (catalog, orders, billing, services, domains, support,
notifications, affiliates, reports, platform admin) that change at different speeds and are built in parallel
by several engineers. Microservices would add distributed-transaction pain to the money path for no benefit at
our scale; an unstructured Laravel app would couple everything to everything.

## Decision
One Laravel application, split into `app/Modules/<Module>` with auto-discovered service providers. Modules
interact only through interfaces in `app/Contracts`, DTOs in `app/Data` and domain events in `app/Events`.
Core (clients, users, roles, settings) is the only module others may import directly.

## Consequences
- Single deploy, single DB transaction for checkout → invoice; easy local dev and testing.
- Parallel development with few merge conflicts; each module testable in isolation (`MODULES=` filter + fakes).
- Shared-kernel changes need central review (CTO). Reports is an explicit read-model exception.
- A module can be extracted later because its seams already exist.
