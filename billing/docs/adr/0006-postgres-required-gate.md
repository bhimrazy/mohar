# ADR 0006 — PostgreSQL is the production database and a required CI gate

- Status: Accepted · 2026-10-17

## Context

SQLite keeps local development and the test suite fast, but production runs on PostgreSQL. PGlite was evaluated
as a lighter Postgres and rejected: PHP needs the Postgres wire protocol with concurrent connections (web, queue
workers, scheduler) and `pglite-socket` serves one connection at a time.

## Decision

PostgreSQL (`postgres:18.6-alpine`, digest-pinned, in `compose.yaml`) is the production-parity database. CI runs
the full suite on both SQLite and PostgreSQL; a red PostgreSQL job blocks merging. Code follows the portability
rules in `docs/ARCHITECTURE.md` (Database section). npm is the single package manager (pnpm files removed).

## Consequences

Portability bugs (case-sensitive LIKE, string aggregates, ordering, aborted transactions) are caught before merge.
Local `composer test:pgsql` reproduces the CI job.
