### Fixed

- **A late provider success on a credit refund no longer fails.** Settling it called the payload masker without the gateway, an argument that #462 made required, so the call threw instead of booking the payout. It now passes the refund's gateway, so that gateway's secrets are masked in the stored payload.
