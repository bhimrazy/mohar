# ADR 0003 — Official Laravel React starter kit (Inertia 3) for admin and client UIs

- Status: Accepted · 2026-10-17

## Decision
Use the official React starter kit (Inertia 3, React 19, TypeScript, Tailwind 4, shadcn/ui on Radix, Fortify,
Wayfinder) as the UI foundation for both the staff admin panel and the client area, with server-driven
navigation (`App\Support\Navigation`). A separate public REST API is a later module, not a v1 prerequisite.
Base UI (as an alternative shadcn primitive layer) was considered; deferred to avoid regenerating kit
components mid-build — the component API stays the same if we migrate later.

## Consequences
Server-side routing/authorization stays in Laravel; no client-side auth state. Shared components live in
`resources/js/components/app`. Wayfinder gives typed route/action helpers.
