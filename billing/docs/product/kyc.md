# Client identity verification (KYC) — Product Spec (v1)

Status: building · Owner: KYC workstream (module `app/Modules/Kyc`, migration block `77xxxx`) · Issue #197, owner
decisions 2026-10-21 (comment on #197) · Clean-room: our own behaviour and wording.

## 1. Purpose

A host can ask clients to prove who they are after sign-up: confirm the account email, give a phone number, and upload
two identity documents that staff check. Verification never blocks a client: ordering, paying and using services work
the same whether the client is verified or not. The main market is Nepal, so the defaults are the `+977` phone prefix and
the three common Nepali documents.

Not in v1: phone OTP, automated ID checks, blocking orders until verification is done.

## 2. Setting

- `kyc.enabled` (Admin → Settings → Identity verification, permission `kyc.settings.manage`). **Off** on fresh installs
  (owner decision 2026-10-21; the issue's "on by default" is superseded). While off, the banner, the client page and
  its routes are not available (routes answer 404), and the retention job still runs.
- `kyc.phone_country_code`, default `+977`: the prefix added to a number entered without one.

## 3. Steps (client area → Identity verification)

One page lists the steps in order, each marked done or to do:

1. **Email.** Done when the account's email is proved: it was set by staff or by an import, or an owner login on this
   account with that exact address has verified it. Otherwise the step says why and links to Account details.
2. **Phone.** A phone field, normalised to `+<country code> <number>`; a number without a prefix gets
   `kyc.phone_country_code` (a leading trunk `0` is dropped). For `+977`, a mobile number is 10 digits starting with 9
   and a landline 8 or 9 digits. Saved to the client's phone. No OTP.
3. **Identity documents.** At least **two different** types from: Citizenship certificate, National ID (NID), Driving
   licence. Each takes the document number and an image of the front and of the back (all three have a back with
   details on it; the type list keeps a per-type flag in case a later type has none).

Client access: owners, and logins with the `profile` area on the account. Every query is scoped to the current
client account.

## 4. Status

Derived, never stored, so it follows later changes (a changed email undoes the email step):

| Status         | When                                                                                                         |
| -------------- | ------------------------------------------------------------------------------------------------------------ |
| Not started    | No current document.                                                                                         |
| In progress    | Documents of fewer than two types, and nothing declined.                                                     |
| Pending review | Current documents of two or more types, at least one waiting for staff, none declined.                       |
| Declined       | A current document was declined; the client sees the reason and can upload a new one.                        |
| Verified       | Email and phone done and two or more types approved (checked first: a declined extra type does not undo it). |

The banner at the top of the client area shows while the status is not Verified, except on the verification page
itself: "Verify your identity to finish setting up your account" with Start verification; a pending-review line while
staff check; "A document was declined. Upload a new one when you can." when declined. Nothing says "to continue":
verification never blocks the client. A verified client sees "Verified on <date>" and no upload prompt.

One term per idea (CTO, 2026-10-21): staff **Decline** a document; its status is **Declined** in the client, staff and
email views.

The client routes answer 404 while `kyc.enabled` is off, from a route middleware that runs before validation. The
admin review routes stay available, so staff can finish the queue after the feature is switched off.

## 5. Files

- Images only, checked by content (not by name): JPEG, PNG or WebP. SVG, PDF and anything else are refused.
  Maximum 5 MB per image.
- The browser shrinks a photo before upload (#288): over 2,800 px on the long edge or over 1 MB, it is scaled to at most
  2,800 px (never enlarged), orientation applied, and saved as JPEG at quality 85 (transparent images stay PNG). The
  client sees a preview with the old and new file size in KB or MB (no pixel sizes) and can choose another photo. A file
  that is not a JPEG, PNG or WebP (for example HEIC) is refused on the spot with a plain message and the field is
  cleared. When the browser cannot shrink a valid photo, the original is sent. This only saves bandwidth; every server check below still applies.
- Stored on the private `objects` disk, encrypted with the application key (`Crypt::encryptString`) under a random
  path. The document number is encrypted at rest too. Neither the bytes, the number nor the path ever appear in an
  Inertia prop, a log line or an audit entry; clients see the last 4 characters of the number only.
- A failed upload never flashes the document number to the session (excluded from old input).
- Every image is decoded and encoded again in its own format with GD before it is encrypted (#233). Metadata (EXIF
  GPS, device and thumbnails, colour profiles, text chunks, JPEG comments) and bytes appended after the image data are
  dropped; a payload built into the pixel data of a lossless PNG is not. The EXIF orientation is applied to the pixels
  first. Refused with a validation error: files GD cannot decode; images above 25 megapixels, and progressive or
  four-channel (CMYK) JPEGs above 20 megapixels (read from the header, before decoding); and, when another upload's
  decode holds the lock for more than 5 seconds, "try again in a moment". Images above 16 megapixels are scaled down
  to 16 megapixels, WebP above 8 megapixels to 8 megapixels. The upload route allows 10 a minute per user and 20 a
  minute per IP address.
- The stored file is at most 5 MB (the upload limit). A larger result is encoded again from the same decoded pixels:
  JPEG or WebP at quality 90, then 75, then 75 scaled to 12 and then 8 megapixels (`kyc.step_down_pixels`). An opaque
  PNG that is too large becomes a JPEG and follows the same steps; a PNG with an alpha channel or `tRNS` chunk stays a
  PNG and is only scaled. Only an image still above 5 MB at 8 megapixels is refused ("The image must be 5 MB or
  smaller.").
- One decode runs at a time across the web processes that share the cache lock store (`kyc:image-decode`). libjpeg
  (progressive and CMYK coefficients) and libwebp (encoder) allocate outside PHP's memory limit, so the caps above are
  set by the container peak. Measured in the production `php` stage (`memory_limit=256M`, `--memory=512m
  --cpus=1`), one upload served by FrankenPHP, cgroup `memory.peak` (idle about 38 MB): CMYK progressive JPEG at
  20 MP 325 MB, progressive 4:4:4 JPEG at 20 MP 289 MB, detailed WebP at 25 MP 298–301 MB, RGBA PNG at 25 MP 285 MB.
  A CMYK progressive JPEG at 25 MP, now refused, measured 343 MB from the command line (about 374 MB served, by the
  #286 security review).
- A document can be replaced while it waits for staff or after it was declined; an approved one cannot. The replaced
  row is marked superseded in the same transaction and its files are deleted after the commit; if that fails, the
  retention job deletes them later.

## 6. Retention

Documents are kept while the client account exists, and deleted **90 days after the account is closed** (owner,
2026-10-21; the issue's 30 days is superseded), and when replaced (§5). A reopened account keeps its documents. An
upload that commits after the account was closed gets its deletion date at write time. The retention listener reads
and updates under the client's row lock (ADR 0021 order: client, then documents).

The daily automation task `kyc.prune_documents` (housekeeping stage): deletes superseded documents, then documents of
clients closed on or before today minus 90 days. Each document is deleted on its own (file, then row); one failure is
logged with the document id only (never the path) and retried the next day. At most 500 documents per run, the rest
the next day. It reads `kyc_documents_delete_after_index` (superseded rows get `delete_after` = the day they are
replaced), skips current documents whose client is no longer closed, and is idempotent.

## 7. Staff review

- Two new **sensitive** permissions (no wildcard grants them, only the exact key or `*`), owner decision 2026-10-21:
  `kyc.documents.view` ("View the identity verification queue and identity documents"), seeded on the Support role,
  and `kyc.documents.review` ("Approve or decline identity documents"), held by full administrators (`*`) only by
  default. A reviewer can also view. Showing documents and every decision require `password.recent`. Staff without
  the review permission see Approve and Decline disabled, with the reason.
- Admin → Clients → Identity verification is an operational page: summary cards (pending review, declined, verified
  = two types approved, not started = open accounts without documents), a status filter (pending review by default,
  declined, verified, not started, all clients) and search by client name or email. Each row shows the client's
  derived status. The admin client profile links to the client's page.
- A client's page shows the status ("Verified on <date>" once verified), the email and phone steps and each current
  document. Numbers and images are hidden until the staff member clicks Show documents for **that client**: a
  password step-up, one audit entry per client, and a window that ends at the earlier of 15 minutes and the end of
  the step-up. The page shows when the window ends. Other clients stay hidden. The page history is encrypted
  (Inertia `encryptHistory`) and cleared (`clearHistory`) on the first page the staff member opens after the window
  ends, on the page a logout leads to when documents were shown, and on every guest view of the login and two-factor
  challenge pages (where a session ended on the server, by idle expiry or a deactivated account, leads). A hover
  prefetch never uses up the clearing. The open page hides the documents when the window ends and reloads. Another
  tab is cleared on its own next request.
  **Hide documents** ends the window early for that client (no step-up, one audit entry, history cleared).
- Images open only through signed links bound to the staff member and the document side, valid until the window
  ends, and only while that client is unlocked; streamed with `Cache-Control: private, no-store` and
  `X-Content-Type-Options: nosniff`, under a filename made of the type and side. A missing or unreadable file answers
  404 and is logged with the document id only.
- Approve, or **Decline** with a reason (required, up to 500 characters). Both stay disabled, with the reason shown,
  until the documents were shown for that client; the server refuses a decision otherwise. A decision locks the
  client, then the document (ADR 0021 §3, the same order as an upload), and refuses a document that changed since
  the page loaded.
- Every decision dispatches `App\Events\Kyc\DocumentDecided`; the Notifications module sends the editable
  `kyc.document_decided` email to the client: the outcome, the reason for a decline, and one button to the
  verification page. Without Notifications nothing is sent and the decision still stands.
- The audit log records each opening, image view, approval and decline (type, side and reason in the properties;
  never the number or the file).

## 8. Acceptance criteria

1. MUST: with `kyc.enabled` off (the default), no banner shows and the client routes answer 404.
2. MUST: with it on, a client who is not verified sees the banner on every client-area page; ordering and paying are
   unaffected.
3. MUST: the email step is done only for an address set by staff or an import, or verified by an owner login with that
   address.
4. MUST: a phone number without a prefix is saved as `+977 …` by default, and an invalid number is refused with the
   reason.
5. MUST: an upload whose bytes are not JPEG, PNG or WebP is refused, whatever its name (an SVG renamed `.png` too), and
   so is an image over 5 MB.
6. MUST: stored files and document numbers are encrypted at rest; the client page and logs never contain them.
7. MUST: a client can only read and change their own account's verification (IDOR test).
8. MUST: replacing a pending or declined document deletes the old files; an approved document cannot be replaced.
9. MUST: the status shows pending review once two different types are uploaded.
10. MUST: the retention task deletes documents 90 days after closure, not before, keeps those of reopened accounts,
    handles each document on its own, caps its work per run and is idempotent.
11. MUST: only staff with the exact `kyc.documents.view` or `kyc.documents.review` permission (or `*`) can open the
    queue or a document link, and only `kyc.documents.review` (or `*`) can decide; Support gets 403 on a decision; `kyc.*` does not grant it; decisions need a recent password.
12. MUST: a document link stops working when the window ends (15 minutes at most) and opens only the document, side and staff member it was signed for, for an unlocked client.
13. MUST: every opening, view, approval and decline writes an audit entry without the number or the file.
14. MUST: a decline needs a reason, the client sees it and is emailed, and a decision on a document that was replaced
    fails.
15. MUST: approve and decline are refused until the documents were shown for that client; showing one client never
    reveals another.
16. MUST: a decision and a re-upload of the same document never deadlock (two-process PostgreSQL test).
17. MUST: after Hide documents no request, in flight or later, brings the unlock back: unlocks are rows changed under a
    lock, and a Show from a page loaded before Hide is refused (two-process PostgreSQL test, issue #258).
18. MUST: the review page learns how long the unlock lasts as seconds counted by the server; a browser clock that is
    ahead or behind changes neither when it hides nor when it reloads (issue #257).
