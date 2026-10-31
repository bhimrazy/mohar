### Changed

- **Invoice emails that carry the PDF are composed by the notifications workers, not by the billing run (#566).** The
  new-invoice, reminder, overdue, updated and receipt emails are queued on the new `document-emails` queue when the
  invoice event happens. A worker then renders the PDF, decides the "A PDF copy is attached" line and logs the
  message. A renewal run, a payment callback or a staff action no longer renders invoice PDFs. The text still claims
  the PDF only when it is attached: a render that fails in the worker is reported, and the email goes without the
  file and without the line. A failure while composing is retried with the mail job's backoff, without logging or
  sending an email twice. Quote and credit or debit note emails are unchanged.
- **A queued invoice email fits the invoice as it is when it is composed (#600).** A reminder for an invoice that has
  been settled since is not sent, and neither is an email whose invoice has been deleted. A receipt states the balance
  its own payment left. A new-invoice email for an invoice that is already paid says so and has no pay button.
- **Operators: the notifications worker reads a third queue (#600).** The worker now reads
  `notifications,webhooks,document-emails`. The new queue is read last, so it never delays sign-in mail, ticket
  replies or webhooks. `deploy/compose.prod.yaml`, the cPanel crontab and the install guide are updated. A server that
  runs its own worker command must add `document-emails`, or these emails stay queued. After a rollback to a release
  from before this change, the jobs wait in the queue until you roll forward. If any failed in the meantime, run
  `php artisan queue:retry --queue=document-emails` (production.md, "Rolling back").
