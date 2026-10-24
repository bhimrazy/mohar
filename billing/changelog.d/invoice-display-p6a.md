### Added

- **Invoices show who they are from and to (#33).** The client and admin invoice pages open with a From block (the
  seller's legal name, address, contact and PAN/VAT number, GSTIN or VAT number) and a Bill to block (the customer's
  name, company, address, email and PAN/VAT or tax number), as the PDF prints them. The buyer comes only from the
  details stored on the invoice when it was written, and the seller from the details stored when it was published, so
  a numbered tax invoice never changes when the client profile or company settings do. A draft shows today's company
  details and says so. The page title names the document as the PDF does: Tax invoice, Proforma invoice or Invoice.
  The period each line covers stays under its description, on phones too.
- **Servers page summary cards (#71).** Active servers, accounts against capacity, servers at 90% of their limit or
  more, and failed module commands in the last 24 hours (linked to the module log for staff who can see it).

### Changed

- **Document numbers and names instead of database ids (#36, #71).** The order page shows the invoice number and the
  fraud provider's name ("Built-in rules"); the service page shows invoice numbers (linked) in Billing periods, the
  order number (linked) and no service id; the module log shows the staff member's name, "System" or "Client"
  instead of `staff:<id>`.
- The admin invoice's separate "Bill to" card is replaced by the From / Bill to block; the payment method and the
  proforma number of a numbered invoice moved into it.
