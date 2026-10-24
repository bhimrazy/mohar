### Fixed

- **Import accounts stays fast on servers with many accounts (#103, #136).** A fetched list is stored one row per
  account instead of one encrypted block, so the page and each import chunk read only the accounts they show or create,
  not the whole list. A page view at 20,000 accounts takes about 20 ms on PostgreSQL where it decrypted everything
  (about 80 MB at 10,000), and a chunk no longer slows as the list grows. Search matches the username, the domain or the
  exact contact email: typing part of an email no longer finds the account.
- **A report line is written once per account (#134).** A chunk that runs twice no longer reports an account twice or
  counts it twice; the database now refuses a second line for the same account in one import.
- **An import that outlives its list keeps its report (#135).** Starting an import extends the list to 24 hours from
  then, a list whose import is still running is never deleted by the page or the daily prune, and finishing or stopping
  keeps it another 24 hours, so the report is there when staff come back. The daily prune deletes at most 100 lists a
  run.
- **Import report and client picker follow-ups (#149).** "Not reached" accounts are marked in the database instead of
  being recognised by their translated message, so the count and the label are right in every language; report lines
  are keyed so an account listed twice no longer collides; Enter in the client picker with its list open never submits
  the surrounding form.
- **Stopped-run report wording (#149).** Correction to the "stopped and failed runs" entry: the report stays on the page after Fetch again, and the next import adds to it only when the last one stopped early; after an import that finished, the next one starts a new report.
- The client picker (invoices, quotes, credit, transactions, Import accounts) opens on click or typing instead of on focus, as of the choice dialog work; stated here for #149 L4.

### Changed

- Upgrade notes: the migrations only add (a table, a column with a default, a unique index). The unique index on report
  lines first deletes any later duplicate line of the same account in one import and recounts that import; reports are
  the last import's only, so at most a report is trimmed. A list fetched before the upgrade is moved into the new table
  the first time it is read. The old `accounts` column stays, empty, until a later release drops it: an import or Match again that the previous release started finishes after the upgrade (its list is moved when the job first reads it); rolling back to the
  previous release shows an upgraded list as empty until it is fetched again. `security:reencrypt` covers both stores.
