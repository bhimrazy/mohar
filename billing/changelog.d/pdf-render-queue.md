### Changed

- **Invoice emails that carry the PDF are composed by the notifications workers, not by the billing run (#566).** The
  new-invoice, reminder, overdue, updated and receipt emails are queued on the `notifications` queue when the invoice
  event happens; a worker then renders the PDF, decides the "A PDF copy is attached" line and logs the message. A
  renewal run, a payment callback or a staff action no longer renders invoice PDFs. The text still claims the PDF only
  when it is attached: a render that fails in the worker is reported and the email goes without the file and without
  the line. Operators: these emails now need a running `notifications` worker before they appear in the email log (the
  documented worker command already covers it). Quote and credit or debit note emails are unchanged.
