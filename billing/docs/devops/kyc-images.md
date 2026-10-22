# Identity images: re-encoding documents stored before #286

Since #286, every identity image a client or staff member uploads is decoded and encoded again by `ImageSanitiser`
before it is encrypted, so EXIF (GPS position, device, thumbnails) and other metadata never reach storage. Documents
uploaded before that upgrade still hold the original bytes. `php artisan kyc:reencode-images` re-encodes them. Run it
**once after upgrading**; documents uploaded since are marked at upload (`kyc_documents.sanitised_at`) and skipped.

## Running it

1. Check what it will do: `php artisan kyc:reencode-images --dry-run`. It prints the documents and images left to
   re-encode, those already done and those flagged, and changes nothing. It exits 3 while there is work.
2. Not during a key change: start it only after every PHP process runs on the current `APP_KEY` (step 4 of
   [key-rotation.md](key-rotation.md)), and stop a running one before that restart (Ctrl+C; it resumes). The run
   encrypts with the key it started with, so a file it writes after `security:reencrypt` has passed that document would
   stay on the old key. It refuses to start while `security:reencrypt` holds its lock (exit 4).
3. Run it **off-peak**. Docker: `docker compose -f deploy/compose.prod.yaml --profile <live colour> exec -T
   app-<live colour> php artisan kyc:reencode-images`. A server: `php artisan kyc:reencode-images`.
4. Run it again until it exits 0 (or 1 with only flagged documents left, below).

Each image is decoded under the same lock (`kyc:image-decode`) and caps as an upload, one image at a time, so the run
never adds a second decode to the container: the peak is one upload's (about 290–325 MB of the 512 MB limit, measured
in #286). While an image decodes, uploads wait for the lock as they would for another upload, and one waiting more
than 5 seconds is refused with "try again in a moment". Off-peak keeps that rare. `--pause` (200 ms by default) waits
between documents so uploads get the lock; the run waits up to `kyc.reencode_decode_wait_seconds` (30) for an upload's
decode and otherwise leaves that document for the next run.

The command reads `--chunk` documents per query (100 by default) and stops after `--limit` documents, if given; the next
run resumes, because each document is marked when it is done. Per image it writes the re-encoded file under a new
random name, records it in `kyc_image_rewrites`, switches the row to it under the client's and the document's row
locks, and deletes the old file after the commit. A run that is killed in between is finished at the start of the next
run: the file the row points at is kept and the other deleted, so no image is lost and none is left behind. A document
that a client or staff replaces or deletes during the run keeps what that change left; the run deletes its own new
files. A staff member reviewing a document while it is re-encoded is asked to reload before deciding, because the
images changed.

A storage or database error on one document (or on a leftover file from an earlier run) is logged with the document id
and the exception class only, never the path; that document is left unchanged and retried on the next run, and the run
carries on with the others. It then ends with exit 1.

The run lock (`kyc:reencode-images`, 6 hours) is released when the process ends, also after a fatal error. If a run is
killed (`kill -9`, a container restart) the next one gets exit 4 until the lock expires; to start sooner, delete the
lock from the cache store (`cache_locks` with the database store).

The run writes one audit-log entry (event `kyc.images.reencoded`) with counts only: documents looked at, re-encoded,
images, flagged, changed during the run, waited too long, and documents left.

| Exit code | Meaning                                                                                                              |
| --------- | -------------------------------------------------------------------------------------------------------------------- |
| 0         | every document is re-encoded                                                                                         |
| 1         | some documents could not be re-encoded and are flagged; they are listed by id and left as stored                     |
| 2         | invalid options                                                                                                      |
| 3         | not finished: a dry run found work, `--limit` stopped the run, an upload held the decode lock, or a document changed |
| 4         | another `kyc:reencode-images` run holds the lock                                                                     |

## Flagged documents

A document whose image cannot be read (the file is missing, or no configured key decrypts it) or re-encoded (GD cannot
decode it, or it is above the pixel limits) is flagged in `kyc_documents.sanitise_issue`, never deleted. Its other image
is still re-encoded. The command lists flagged documents by id on every run and exits 1 while any is left. Staff see a
note on the document in Admin → Clients → Identity verification. Ask the client to upload the document again: the
replaced document is deleted with its images by the existing replace and retention paths, and the flag goes with it.
`--retry-flagged` tries flagged documents again, for example after restoring a missing file or adding an old key to
`APP_PREVIOUS_KEYS`. It encodes both images again, so the one already re-encoded goes through a second lossy encode
(JPEG or WebP at quality 90).
