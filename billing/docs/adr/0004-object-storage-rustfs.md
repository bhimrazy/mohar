# ADR 0004 — S3-compatible object storage (RustFS) for business files

- Status: Accepted · 2026-10-17

## Decision
Ticket attachments, invoice/quote PDFs and report exports go to the `objects` filesystem disk (Flysystem S3
driver). Locally it is backed by RustFS (`compose.yaml`, pinned `rustfs/rustfs:1.0.0`); production can use any
S3-compatible store. Files are private; downloads are authorized controller responses or short-lived
temporary URLs. `OBJECTS_DRIVER=local` keeps dev possible without Docker; tests use `Storage::fake('objects')`.

## Consequences
Stateless app servers, independent scaling/backup of files, no files on web nodes.
