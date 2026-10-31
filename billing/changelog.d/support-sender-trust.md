### Security

- **Inbound support mail no longer attaches to an account email nobody verified.** Email to support, the public
  ticket form and email replies link a sender to a client account only through an address staff or an import set,
  a client login that verified its email (owner or sub-user), or a contact. An account email the client typed in
  counts once a login of that client verifies the same address. Mail from any other address is handled as from an
  unknown sender: a guest ticket, a bounce in clients-only departments, or a reply held as blocked for staff. A
  client can no longer pull a stranger's support mail into their account by setting the account email to that
  stranger's address, nor detach another account's mail by copying its address.
  Upgrade note: a self-registered client whose login has not verified its email now gets guest tickets from inbound
  mail until that login verifies the address; staff can still link the ticket to the account.
