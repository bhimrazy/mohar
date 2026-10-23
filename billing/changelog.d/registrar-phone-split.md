### Changed

- **Registrar contact phones use one calling-code table.** ResellerClub and Namecheap now split a contact's phone with
  the shared table of country calling codes, so numbers from any country work (before, only 16 countries did, and a
  number without a separator such as `+81312345678` was refused). Italian numbers keep their leading 0. Numbers typed
  with an exit prefix (`0011`, `011`) or an unknown code are refused with a message before anything is sent to the
  registrar. A code written as `+NNN.` that is not an assigned country calling code is no longer passed through.
