### Security

- **Every admin route is now tested to refuse a staff member with no permissions, and a signed-in client.** A sweep
  calls all `admin.*` routes (each settings section and bulk action included) and fails on any route that answers
  without a permission check. Four routes are open to all staff by design: the admin dashboard, the command-palette
  search (each result group still needs its permission), a staff member's own appearance preference and the static
  theme schema. No unprotected route was found.

### Changed

- **Billing validates and authorizes every admin and client request through Form Requests.** No permission changes.
  Two answers to invalid requests change: a bulk invoice action from a staff member without its permission is now
  refused with 403 before its input is checked (it used to show the validation errors first), and resolving a refund
  that is no longer waiting with an invalid form now shows the validation errors instead of a 404.
