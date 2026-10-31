### Security

- **Keys a provisioning or registrar driver declares secret are now masked.** A driver that implements
  `DeclaresSensitiveKeys` has those keys masked in the module and registrar command logs. The values under them are
  also masked wherever they occur: in the stored payloads, errors, failure items, activity entries and registrar
  messages. That includes values the driver returns inside JSON, XML (CDATA and attributes included) or
  `name=value` strings, quoted or not.
    - A value of 4 to 7 bytes is masked only as a whole word in text, and always in payloads.
    - When a payload cannot be read in full, it is stored as `[redacted]`, with its text. That happens past the node
      limit, or when a string uses a declared key in a form the host does not read.
    - A driver whose `sensitiveKeys()` throws fails the command before anything is sent.
    - Built-in drivers declare nothing, so their logs are unchanged.
