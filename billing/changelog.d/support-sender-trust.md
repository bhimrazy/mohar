### Security

- **Inbound support mail no longer attaches to an account email nobody verified.** Email to support, the public
  ticket form and email replies link a sender to a client account only through an address staff or an import set,
  a client login that verified its email (owner or sub-user), or a contact. Mail from any other address is handled
  as from an unknown sender: a guest ticket, a bounce in clients-only departments, or a reply held as blocked for
  staff. A client can no longer pull a stranger's support mail into their account by setting the account email to
  that stranger's address, nor detach another account's mail by copying its address.
- **Linking a guest ticket to a client, or changing its client, replaces its access link.** The old link stops
  working; the client follows the ticket in the client area.

    Upgrade notes: these addresses no longer attach inbound mail or public-form tickets to their account until a
    login verifies them, so their tickets open as guest tickets (or bounce in clients-only departments):
    - account emails set in the client area (`email_source` `client`) that differ from every verified login's email.
      The 2026-10-20 backfill marked a client `client` after any client-area account-details save, even one that left
      the email unchanged, and when the activity log had no staff entry that set the email;
    - logins that have not verified their email, including self-registered owners, sub-users invited by staff who have
      not accepted yet, and imported logins without `verified`.

    On the public ticket form, any address that is an account, login or contact email, verified or not, still gets
    only a confirmation page with the access link mailed to it, and the ticket is flagged as an unverified requester.
    It is attached to the account only through a verified address. Staff can link a guest ticket to the right
    account from the ticket view.
